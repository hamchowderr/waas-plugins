#!/usr/bin/env python3
"""Builds and drives the WaaS test storefront on Coolify. See docs/test-storefront.md.

    storefront.py create            # project, service, variables (password generated, never printed)
    storefront.py push              # build waas-host + waas-payments and copy them into the service
    storefront.py setup             # bootstrap, install, plugins, configure, pages, product, payments
    storefront.py run '<command>'   # one shell command in the wordpress container, as root
    storefront.py remove            # delete the service with its volumes, then the project

Talks to the Coolify API at COOLIFY_URL (default https://coolify.otakusolutions.io) with
COOLIFY_TOKEN, or with none where a proxy injects credentials. `create` reads the provisioning
API key and secret from WAAS_TEST_KEY and WAAS_TEST_SECRET.
"""
import base64
import json
import os
import secrets
import string
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
API = os.environ.get('COOLIFY_URL', 'https://coolify.otakusolutions.io').rstrip('/') + '/api/v1'
PROJECT = 'WaaS Storefront (test)'
SERVICE = 'storefront-test'
DOMAIN = 'storefront-test.191.101.15.236.sslip.io'
SERVER = 'sq3c52uczu81384adrlo7w77'  # localhost, the Coolify host
CONTAINER = 'wordpress'
REMOTE = '/opt/wpcli/x'  # on the wpcli volume, so it survives restarts
# Coolify sends a service's .env to the server as one shell argument (128 KB at most), so the
# package goes up in chunks, one restart each. 45 KB already failed; 20 KB works.
CHUNK = 20000
STEPS = ['bootstrap', 'install', 'plugins', 'configure', 'pages', 'product', 'payments']


def api(method, path, body=None):
    req = urllib.request.Request(API + path, method=method, headers={'accept': 'application/json'})
    if os.environ.get('COOLIFY_TOKEN'):
        req.add_header('authorization', 'Bearer ' + os.environ['COOLIFY_TOKEN'])
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        req.add_header('content-type', 'application/json')
    try:
        with urllib.request.urlopen(req, data, timeout=60) as res:
            text = res.read().decode()
    except urllib.error.HTTPError as e:
        # Coolify's error bodies carry a message, never the request body.
        sys.exit(f'Coolify {method} {path}: HTTP {e.code} {e.read().decode()[:300]}')
    return json.loads(text) if text else None


def project_uuid():
    for p in api('GET', '/projects'):
        if p['name'] == PROJECT:
            return p['uuid']
    return None


def service_uuid():
    for s in api('GET', '/services'):
        if s['name'] == SERVICE:
            return s['uuid']
    sys.exit(f'No service named {SERVICE}; run create first')


def healthy(uuid, minutes):
    deadline = time.time() + minutes * 60
    while time.time() < deadline:
        time.sleep(8)
        s = api('GET', f'/services/{uuid}')
        statuses = [c.get('status') or '' for c in s.get('applications', []) + s.get('databases', [])]
        if statuses and all(x.startswith('running') and 'unhealthy' not in x and 'starting' not in x for x in statuses):
            return True
    return False


def wait_healthy(uuid):
    # A restart now and then leaves the wordpress container exited; a start brings it back.
    if not healthy(uuid, 4):
        api('POST', f'/services/{uuid}/start')
        if not healthy(uuid, 8):
            sys.exit(f'{SERVICE} did not become healthy')


def run(uuid, command, timeout=900):
    """Runs one command through an on-demand scheduled task (Coolify has no exec endpoint)."""
    if len(command) > 255:
        sys.exit('Coolify keeps 255 characters of a task command')
    task = api('POST', f'/services/{uuid}/scheduled-tasks', {
        'name': 'storefront-' + secrets.token_hex(3), 'command': command, 'container': CONTAINER,
        'frequency': '0 0 1 1 *', 'timeout': timeout, 'enabled': False,
    })
    try:
        api('POST', f'/services/{uuid}/scheduled-tasks/{task["uuid"]}/execute')
        deadline = time.time() + timeout + 60
        while time.time() < deadline:
            time.sleep(4)
            done = [e for e in api('GET', f'/services/{uuid}/scheduled-tasks/{task["uuid"]}/executions') or []
                    if e.get('status') in ('success', 'failed') or e.get('finished_at')]
            if done:
                print(done[0].get('message') or '')
                if done[0]['status'] != 'success':
                    sys.exit(f'Task failed: {command}')
                return
        sys.exit(f'Task timed out: {command}')
    finally:
        api('DELETE', f'/services/{uuid}/scheduled-tasks/{task["uuid"]}')


def set_env(uuid, key, value, locked=False):
    api('PATCH', f'/services/{uuid}/envs', {'key': key, 'value': value, 'is_literal': True, 'is_shown_once': locked})


def compose(with_package):
    text = open(os.path.join(HERE, 'compose.yml')).read()
    if with_package:
        text = text.replace('      WAAS_API_SECRET: ${WAAS_API_SECRET}\n',
                            '      WAAS_API_SECRET: ${WAAS_API_SECRET}\n      WAAS_PKG: ${WAAS_PKG:-}\n')
    return base64.b64encode(text.encode()).decode()


def create():
    for var in ('WAAS_TEST_KEY', 'WAAS_TEST_SECRET'):
        if not os.environ.get(var):
            sys.exit(f'{var} is not set')
    project = project_uuid() or api('POST', '/projects', {'name': PROJECT, 'description': 'Throwaway test storefront for the WaaS chain'})['uuid']
    if any(s['name'] == SERVICE for s in api('GET', '/services')):
        sys.exit(f'{SERVICE} already exists; remove it first')
    uuid = api('POST', '/services', {
        'name': SERVICE, 'description': 'WaaS test storefront. Runbook docs/test-storefront.md in waas-plugins',
        'project_uuid': project, 'environment_name': 'production', 'server_uuid': SERVER,
        'docker_compose_raw': compose(False), 'urls': [{'name': CONTAINER, 'url': 'https://' + DOMAIN}],
        'instant_deploy': False,
    })['uuid']
    alphabet = string.ascii_letters + string.digits
    # Readable in Coolify (masked until clicked) so the owner can log in; the API keys are locked.
    set_env(uuid, 'WP_ADMIN_PASSWORD', ''.join(secrets.choice(alphabet) for _ in range(32)))
    set_env(uuid, 'WAAS_API_KEY', os.environ['WAAS_TEST_KEY'], locked=True)
    set_env(uuid, 'WAAS_API_SECRET', os.environ['WAAS_TEST_SECRET'], locked=True)
    api('POST', f'/services/{uuid}/start')
    wait_healthy(uuid)
    print(f'project {project}, service {uuid}')


def build():
    """waas-host and waas-payments as installable zips, with the setup scripts, in one tar.xz."""
    out = tempfile.mkdtemp()
    pkg = os.path.join(out, 'pkg')
    os.makedirs(pkg)
    for name in ('waas-host', 'waas-payments'):
        src = os.path.join(out, name)
        subprocess.run(['cp', '-R', os.path.join(ROOT, 'packages', name), src], check=True)
        subprocess.run(['rm', '-rf', 'vendor', 'tests', 'phpunit.xml.dist', 'node_modules'], cwd=src, check=True)
        subprocess.run(['composer', 'install', '--no-dev', '-q'], cwd=src, check=True)
        subprocess.run(['zip', '-qr9', os.path.join(pkg, name + '.zip'), name], cwd=out, check=True)
    subprocess.run(['cp'] + [os.path.join(HERE, 'remote', f) for f in os.listdir(os.path.join(HERE, 'remote'))] + [pkg], check=True)
    archive = os.path.join(out, 'pkg.txz')
    subprocess.run(['tar', '-cJf', archive, '-C', pkg, '.'], check=True)
    return open(archive, 'rb').read()


def push():
    uuid = service_uuid()
    data = base64.b64encode(build()).decode()
    chunks = [data[i:i + CHUNK] for i in range(0, len(data), CHUNK)]
    api('PATCH', f'/services/{uuid}', {'docker_compose_raw': compose(True)})
    run(uuid, 'rm -rf /opt/wpcli/pkg && mkdir -p /opt/wpcli/pkg')
    for i, chunk in enumerate(chunks):
        print(f'chunk {i + 1}/{len(chunks)}')
        set_env(uuid, 'WAAS_PKG', chunk)
        api('POST', f'/services/{uuid}/restart')
        time.sleep(15)
        wait_healthy(uuid)
        run(uuid, f'printenv WAAS_PKG > /opt/wpcli/pkg/c{i:02d}')
    run(uuid, f'cd /opt/wpcli/pkg && cat c* | tr -d "\\n" | base64 -d > p.txz && rm -rf {REMOTE} && mkdir -p {REMOTE} && tar -xJf p.txz -C {REMOTE} && ls {REMOTE}')
    # Drop the package variable again.
    api('PATCH', f'/services/{uuid}', {'docker_compose_raw': compose(False)})
    for env in api('GET', f'/services/{uuid}/envs'):
        if env['key'] == 'WAAS_PKG':
            api('DELETE', f'/services/{uuid}/envs/{env["uuid"]}')
    api('POST', f'/services/{uuid}/restart')
    time.sleep(15)
    wait_healthy(uuid)


def setup(steps):
    uuid = service_uuid()
    for step in steps or STEPS:
        print(f'== {step}')
        run(uuid, f'sh {REMOTE}/setup.sh {step}')


def remove():
    uuid = service_uuid()
    api('DELETE', f'/services/{uuid}?delete_configurations=true&delete_volumes=true&docker_cleanup=true&delete_connected_networks=true')
    while any(s['name'] == SERVICE for s in api('GET', '/services')):
        time.sleep(5)
    project = project_uuid()
    if project:
        api('DELETE', f'/projects/{project}')
    print('removed')


if __name__ == '__main__':
    cmd, args = (sys.argv[1] if len(sys.argv) > 1 else ''), sys.argv[2:]
    if cmd == 'create':
        create()
    elif cmd == 'push':
        push()
    elif cmd == 'setup':
        setup(args)
    elif cmd == 'run' and args:
        run(service_uuid(), args[0])
    elif cmd == 'remove':
        remove()
    else:
        sys.exit(__doc__)
