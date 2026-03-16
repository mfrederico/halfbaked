<?php
    /**
     * List all Shopify products (for inventory sync)
     */
    public static function listShopifyProducts(array $credentials): array
    {
        $adapter = new self($credentials);
        
        if (!$adapter->accessToken) {
            throw new \Exception('No access token found');
        }
        
        $shopDomain = $adapter->shopDomain;
        $url = "https://{$shopDomain}/admin/api/{$adapter->apiVersion}/products.json";
        
        // Paginate through all products
        $allProducts = [];
        $page = 1;
        $limit = 250; // Shopify max per page
        
        do {
            $params = [
                'limit' => $limit,
                'page_info' => $_GET['page_info'] ?? null
            ];
            
            $response = $adapter->httpRequest($url, 'GET', [], http_build_query($params));
            
            if ($response['success']) {
                $data = json_decode($response['body'], true);
                
                if (isset($data['products'])) {
                    $allProducts = array_merge($allProducts, $data['products']);
                    
                    // Check for pagination
                    if (isset($data['next_page_info'])) {
                        $_GET['page_info'] = $data['next_page_info'];
                        $page++;
                    } else {
                        break;
                    }
                }
            } else {
                throw new \Exception('Failed to fetch products: ' . $response['body']);
            }
        } while (count($allProducts) < $data['total_count']);
        
        return [
            'success' => true,
            'products' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'shopify_id' => (string)$p['id'], // Ensure string for BC
                    'title' => $p['title'] ?? 'Untitled',
                    'variants' => array_map(function($v) {
                        return [
                            'id' => (int)$v['id'],
                            'sku' => $v['sku'] ?? '',
                            'barcode' => $v['barcode'] ?? '',
                            'price' => (float)str_replace(',', '', $v['price']),
                            'compare_at_price' => isset($v['compare_at_price']) ? (float)str_replace(',', '', $v['compare_at_price']) : null,
                            'inventory_quantity' => (int)$v['inventory_quantity'],
                            'fulfillment_service' => $v['fulfillment_service'] ?? ''
                        ];
                    }, $p['variants']),
                    'images' => array_map(function($i) {
                        return [
                            'id' => (int)$i['id'] ?? 0,
                            'src' => $i['src'] ?? '',
                            'position' => (int)$i['position'] ?? 0
                        ];
                    }, $p['images']),
                    'options' => array_map(function($o) {
                        return [
                            'name' => $o['name'],
                            'values' => is_array($o['values']) ? $o['values'] : [$o['values']]
                        ];
                    }, $p['options'])
                ];
            }, $allProducts),
            'total_count' => count($allProducts)
        ];
    }
