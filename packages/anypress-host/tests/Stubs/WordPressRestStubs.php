<?php

// The parts of WordPress's REST API classes our controllers use, so they load without WordPress.

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;
        private $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public $headers = [];

        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function header($key, $value)
        {
            $this->headers[$key] = $value;
        }

        public function get_headers()
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $method;
        private $route;
        private $params;
        private $headers = [];
        private $body = '';

        public function __construct($method = '', $route = '', array $params = [])
        {
            $this->method = $method;
            $this->route = $route;
            $this->params = $params;
        }

        public function get_header($key)
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function set_header($key, $value)
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_method()
        {
            return $this->method;
        }

        public function get_route()
        {
            return $this->route;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }

        public function get_body()
        {
            return $this->body ?? '';
        }

        public function set_body($body)
        {
            $this->body = $body;
        }
    }
}
