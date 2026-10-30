<?php

namespace WaaSClient\Forms;

/** Thrown in place of the exit at the end of wp_send_json(), so the importer's answer can be read and used. */
class SendJsonStop extends \RuntimeException
{
}
