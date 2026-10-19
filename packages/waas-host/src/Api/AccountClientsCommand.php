<?php

namespace WaaSHost\Api;

use InvalidArgumentException;
use WP_CLI;

/**
 * Manages the front ends allowed to use the account API's token sign-in.
 */
class AccountClientsCommand
{
    /**
     * Registers a front end and prints its client id and secret. The secret is shown only this once.
     *
     * ## OPTIONS
     *
     * <name>
     * : A name to recognise it by, like "anypress.io".
     *
     * --redirect-uris=<uris>
     * : Comma-separated callback URLs, matched exactly. https only (http for localhost).
     *
     * ## EXAMPLES
     *
     *     wp waas-host account-client add anypress.io --redirect-uris=https://anypress.io/api/auth/callback/anypress
     */
    public function add($args, $assoc_args): void
    {
        try {
            $client = AccountClients::create($args[0], explode(',', (string) ($assoc_args['redirect-uris'] ?? '')));
        } catch (InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }

        WP_CLI::line('client_id: ' . $client['client_id']);
        WP_CLI::line('client_secret: ' . $client['client_secret']);
        WP_CLI::success('Registered. Keep the secret on the front end\'s server only; it is not shown again.');
    }

    /**
     * Lists the registered front ends.
     *
     * @subcommand list
     */
    public function list_clients($args, $assoc_args): void
    {
        $rows = [];
        foreach (AccountClients::all() as $id => $client) {
            $rows[] = [
                'client_id' => $id,
                'name' => $client['name'] ?? '',
                'redirect_uris' => implode(', ', (array) ($client['redirect_uris'] ?? [])),
                'created' => gmdate('Y-m-d H:i', (int) ($client['created'] ?? 0)),
            ];
        }
        WP_CLI\Utils\format_items('table', $rows, ['client_id', 'name', 'redirect_uris', 'created']);
    }

    /**
     * Removes a front end. Its customers can't refresh their sign-in after this.
     *
     * ## OPTIONS
     *
     * <client_id>
     * : The client to remove.
     */
    public function remove($args, $assoc_args): void
    {
        if (!AccountClients::remove($args[0])) {
            WP_CLI::error('No such client.');
            return;
        }
        WP_CLI::success('Removed.');
    }
}
