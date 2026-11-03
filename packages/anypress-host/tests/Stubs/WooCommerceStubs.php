<?php

// The parts of WooCommerce's product object that StoreProduct uses, kept in memory.
if (!class_exists('WC_Product')) {
    class WC_Product
    {
        public array $meta;
        public int $saves = 0;
        private int $id;

        public function __construct(int $id = 0, array $meta = [])
        {
            $this->id = $id;
            $this->meta = $meta;
        }

        public function get_id()
        {
            return $this->id;
        }

        public function get_meta($key = '', $single = true)
        {
            return $this->meta[$key] ?? '';
        }

        public function meta_exists($key = '')
        {
            return array_key_exists($key, $this->meta);
        }

        public function update_meta_data($key, $value)
        {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data($key)
        {
            unset($this->meta[$key]);
        }

        public function save()
        {
            $this->saves++;
            return $this->id;
        }
    }
}
