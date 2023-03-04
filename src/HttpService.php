<?php

namespace Blackfire;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use \Db;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;
use League\Csv\Reader;
use League\Csv\Statement;
use \Blackfire\BlackfireSyncService;

class HttpService
{
    private $client;
    private $cookieJar;
    private $address = 'https://www.blackfire.eu';

    public function __construct($user, $password)
    {
        $this->client = new Client();

        $lastCookie = Db::getInstance()->getRow("select * from " . _DB_PREFIX_ . "blackfiresync order by `expires` desc");
        
        if ($lastCookie && $lastCookie["expires"] > time() + 60 * 60) 
        {
            $this->cookieJar = new CookieJar(false, [
                [
                    "Name" => ".ASPXAUTH_SS",
                    "Value" => $lastCookie["cookie"],
                    "Domain" => "www.blackfire.eu",
                    "Path" => "/",
                    "Max-Age" => null,
                    "Expires" => $lastCookie["expires"],
                    "Secure" => false,
                    "Discard" => false,
                    "HttpOnly" => true,
                    "SameSite" => "Lax"
                ]
            ]);
        }
        else
        {
            $this->login($user, $password);
        }
    }

    public function login($user, $password)
    {
        $this->cookieJar = new CookieJar();

        $response = $this->client->post($this->address . '/en-gb/profile/login', [
            'body' => [
                "UserName" => $user,
                "Password" => $password,
                "RememberMe" => "true",
            ],
            'cookies' => $this->cookieJar
        ]);

        $cookies = $this->cookieJar->toArray();
        $cookies = array_filter($cookies, function($c) {
            return $c["Name"] == ".ASPXAUTH_SS";
        });
        
        $cookies = array_values($cookies);
        $cookie = $cookies[0];

        $body = (string) $response->getBody();

        if (!strpos($body, "Logout"))
        {
            dump("Not logged in");
        }
        else
        {
            Db::getInstance()->insert("blackfiresync", [
                "cookie" => $cookie["Value"], 
                "expires" => time() + 2 * 60 * 60
            ]);
        }
    }

    public function getAccountInfo()
    {
        $response = $this->client->get($this->address . '/en-gb/profile', [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->loadStr($body);

        $accountData = $dom->find('div.account-info-inside .control .field');

        return [
            "name" => trim($accountData[0]->text()),
            "email" => trim($accountData[1]->text())
        ];
    }

    public function getCategories()
    {
        // use about-us page, as home page loads the slowest
        $response = $this->client->get($this->address . '/en-gb/about-us', [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->setOptions(
            (new Options())
            ->setPreserveLineBreaks(true)
        );
        $dom->loadStr($body);

        $categoriesDom = $dom->find('span[plaintext^=Brands]')->parent->parent->find('div.nav-wrapper ul.nav-list li.nav-item.nav-item-block');

        $categories = [];

        foreach($categoriesDom as $c)
        {
            $link = $c->find('a.link-lvl-2');
            $url = $link->getTag()->getAttribute("href")->getValue();

            $category = [
                "name" => trim($link->text()),
                "link" => $url,
                "subcategories" => []
            ];

            $response = $this->client->get($this->address . $url, [
                'cookies' => $this->cookieJar
            ]);

            $body = (string) $response->getBody();

            $dom = new Dom;
            $dom->setOptions(
                (new Options())
                ->setPreserveLineBreaks(true)
            );
            $dom->loadStr($body);

            $subcategories = $dom->find('div.facets h4[plaintext^=Product category]')->parent->parent->find('ul.list-facets li');

            foreach($subcategories as $s)
            {
                $link = $s->find('a.facet-item');
                $name = trim($link->text());
                $url = $link->getTag()->getAttribute("href")->getValue();

                if ($name == $category['name']) continue;

                $category["subcategories"][] = [
                    "name" => str_replace($category['name'] . '\\', '', $name),
                    "link" => $url
                ];
            }

            $categories[] = $category;
        }

        return $categories;
    }

    public function getProducts($categoryID, $subcategoryID)
    {
        if (!$categoryID || !$subcategoryID) return [];

        $categories = BlackfireSyncService::getCategories();
        $categoryLink = $categories[$categoryID]['subcategories'][$subcategoryID]['link'];

        $dom = $this->fetchProductsPage($categoryLink, 1);
        $products = $this->parseProducts($dom);
        
        $pagination = $dom->find('ul.pager-list li');
        $liCount = $pagination->count();

        if ($liCount > 0)
        {
            $lastLi = $pagination->offsetGet($liCount - 1);
            $pagesCount = $lastLi->find('a')->text();

            for ($i = 2; $i <= $pagesCount; $i++) 
            {
                $dom = $this->fetchProductsPage($categoryLink, $i);

                $products = array_merge($products, $this->parseProducts($dom));
            }
        }

        return $products;
    }

    private function fetchProductsPage($categoryLink, $page)
    {
        $response = $this->client->get($this->address . $categoryLink . '?page=' . $page, [
            'cookies' => $this->cookieJar,
            'headers' => [
                'x-requested-with' => 'XMLHttpRequest'
            ]
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->setOptions(
            (new Options())
            ->setPreserveLineBreaks(true)
        );
        $dom->loadStr($body);

        return $dom;
    }

    private function parseProducts($dom)
    {
        $productItems = $dom->find('div.product-list .l-products-item');
        $products = [];

        foreach($productItems as $productItem)
        {
            $productID = $productItem->getTag()->getAttribute('data-id')->getValue();

            $attributes = $productItem->find('div.product-attributes span.value');
            $attributes = $this->parseAttributes($attributes);
            
            $price = trim($productItem->find('.lbl-price')->text());
            $price = str_replace('€ ', '', $price);
            $oldPrice = trim($productItem->find('.list-price.font-smaller')->text());
            $oldPrice = str_replace('€ ', '', $oldPrice);

            $inPreorder = $productItem->find('.pdp-quantity-preorder');
            if ($inPreorder->count() > 0)
            {
                $inPreorder = trim($inPreorder->text());
                $inPreorder = str_replace('Already in pre-order : ', '', $inPreorder);
                $inPreorder = (int) $inPreorder;
            }
            else
            {
                $inPreorder = 0;
            }

            $imageSmall = $productItem->find('.product-tile .hyp-thumbnail span.thumbnail noscript img')->getTag()->getAttribute('src')->getValue();

            $products[$productID . 'a'] = [
                'id' => $productID,
                'image_small' => $this->address . $imageSmall,
                'name' => trim($productItem->find('a.product-title span')->text()),
                'ean' => $attributes['ean'],
                'ref' => $attributes['ref'],
                'release_date' => $attributes['release_date'],
                'preorder_deadline' => $attributes['preorder_deadline'],
                'status' => $attributes['status'],
                'stock' => trim($productItem->find('.erpbase_stocklevel span')->text()),
                'price' => $price,
                'old_price' => $oldPrice,
                'in_preorder' => $inPreorder
            ];
        }

        return $products;
    }

    private function matchesRule($value, $rule)
    {
        switch ($rule) {
            case 'any':
                return true;
            
            case 'ean':
                $expr = "/^[0-9]{8,14}$/";
                return !!preg_match($expr, $value);

            case 'date':
                return date_parse_from_format('d/m/Y', $value)['error_count'] == 0;

            case 'status':
                return in_array($value, ['Closed', 'Close-out', 'On Sale', 'Preorder']);
        }
    }

    private function parseAttributes($attributes)
    {
        $attributes = array_map(function($attr) {return trim($attr->text());}, $attributes->toArray());
        $rules = ['any', 'ean', 'date', 'date', 'status'];
        $results = [];

        $matches = array_map(function($rule) use ($attributes) {
            return array_map(function($attribute) use ($rule) {
                return $this->matchesRule($attribute, $rule);
            }, $attributes);
        }, $rules);

        for($i=0; $i < count($rules); $i++)
        {
            // find rule with lowest amount of matching attributes
            $minIndex = null;
            $minAmount = 100000;

            foreach($matches as $index => $match)
            {
                $amount = array_reduce($match, function($acc, $val) {
                    if ($val === true) return $acc+1;
                    else return $acc;
                }, 0);

                if ($amount < $minAmount)
                {
                    $minIndex = $index;
                    $minAmount = $amount;
                }
            }

            $foundMatching = false;

            // find first value to the left that matches the rule
            foreach ($matches[$minIndex] as $attributeIndex => $isMatching)
            {
                if ($isMatching === true)
                {
                    $results[$minIndex] = $attributes[$attributeIndex];
                    unset($matches[$minIndex]);
                    unset($attributes[$attributeIndex]);

                    foreach ($matches as $matchIndex => $match)
                    {
                        $matches[$matchIndex][$attributeIndex] = false;
                    }

                    // count attributes before and after the found one
                    $attributesBeforeCount = 0;
                    $attributesAfterCount = 0;

                    foreach ($attributes as $attrIndex => $attr)
                    {
                        if ($attributeIndex > $attrIndex) $attributesBeforeCount++;
                        if ($attributeIndex < $attrIndex) $attributesAfterCount++;
                    }

                    // count remaining matches before and after the found one
                    $matchesBeforeCount = 0;
                    $matchesAfterCount = 0;

                    foreach ($matches as $matchIndex => $match)
                    {
                        $amount = array_reduce($match, function($acc, $val) {
                            if ($val === true) return $acc+1;
                            else return $acc;
                        }, 0);

                        if ($minIndex > $matchIndex && $amount > 0) $matchesBeforeCount++;
                        if ($minIndex < $matchIndex && $amount > 0) $matchesAfterCount++;
                    }

                    // if there are any matches before or after but there are no attributes we need to 
                    // remove the matches

                    if ($matchesBeforeCount > 0 && $attributesBeforeCount == 0)
                    {
                        foreach($matches as $matchIndex => $match)
                        {
                            if ($matchIndex >= $minIndex) continue;

                            foreach($match as $matchAttributeIndex => $matchValue)
                            {
                                $matches[$matchIndex][$matchAttributeIndex] = false;
                            }
                        }
                    }

                    if ($matchesAfterCount > 0 && $attributesAfterCount == 0)
                    {
                        foreach($matches as $matchIndex => $match)
                        {
                            if ($matchIndex <= $minIndex) continue;

                            foreach($match as $matchAttributeIndex => $matchValue)
                            {
                                $matches[$matchIndex][$matchAttributeIndex] = false;
                            }
                        }
                    }

                    $foundMatching = true;
                    break;
                }
            }

            // if not found any matching values to the rules
            if (!$foundMatching)
            {
                $results[$minIndex] = '';
                unset($matches[$minIndex]);
            }
        }

        return [
            'ref' => $results[0],
            'ean' => $results[1],
            'release_date' => $results[2],
            'preorder_deadline' => $results[3],
            'status' => $results[4]
        ];
    }

    public function getProduct($productID)
    {
        if (!$productID) return false;

        $response = $this->client->get($this->address . '/en-gb/' . $productID, [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->loadStr($body);

        $imageAttributes = $dom->find('img.custom-lazy')->getTag()->getAttributes();
        
        if (array_key_exists('data-zoom-image', $imageAttributes))
        {
            $image = $dom->find('img.custom-lazy')->getTag()->getAttribute('data-zoom-image')->getValue();
        }
        else
        {
            $image = $dom->find('img.custom-lazy')->getTag()->getAttribute('data-src')->getValue();
        }
        
        return [
            'name' => trim($dom->find('.font-product-title')->text()),
            'image' => $dom->find('.details-img .carousel-image-m-wrapper noscript img')->getTag()->getAttribute('src')->getValue(),
            'description' => $dom->find('.description.fr-view')->innerHtml(),
            'manufacturer' => trim($dom->find('td[plaintext^=Manufacturer Code]')->parent->find('td.value')->text()),
            'image' => $this->address . $image
        ];
    }
}