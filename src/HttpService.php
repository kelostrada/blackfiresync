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

            $offset = 0;
            $productNo = trim($attributes->offsetGet($offset++)->text());
            $ean = trim($attributes->offsetGet($offset++)->text());

            if (date_parse($ean)['error_count'] == 0)
            {
                $ean = '';
                $offset--;
            }

            $releaseDate = trim($attributes->offsetGet($offset++)->text());
            $preorderDeadline = trim($attributes->offsetGet($offset++)->text());
            $status = trim($attributes->offsetGet($offset++)->text());
            
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
            $imageLarge = str_replace('small', 'large', $imageSmall);

            $products[$productID . 'a'] = [
                'id' => $productID,
                'image_small' => $this->address . $imageSmall,
                'image_large' => $this->address . $imageLarge,
                'name' => trim($productItem->find('a.product-title span')->text()),
                'ean' => $ean,
                'ref' => $productNo,
                'release_date' => $releaseDate,
                'preorder_deadline' => $preorderDeadline,
                'status' => $status,
                'stock' => trim($productItem->find('.erpbase_stocklevel span')->text()),
                'price' => $price,
                'old_price' => $oldPrice,
                'in_preorder' => $inPreorder
            ];
        }

        return $products;
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

        return [
            'name' => trim($dom->find('.font-product-title')->text()),
            'image' => $dom->find('.details-img .carousel-image-m-wrapper noscript img')->getTag()->getAttribute('src')->getValue(),
            'description' => $dom->find('.description.fr-view')->innerHtml(),
            'manufacturer' => trim($dom->find('td[plaintext^=Manufacturer Code]')->parent->find('td.value')->text())
        ];
    }
}