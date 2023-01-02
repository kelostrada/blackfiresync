<?php

namespace Blackfire;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use \Db;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;
use League\Csv\Reader;
use League\Csv\Statement;

class HttpService
{
    private $client;
    private $cookieJar;

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

        $response = $this->client->post('https://www.blackfire.eu/en-gb/profile/login', [
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
                "expires" => time() + 24 * 60 * 60
            ]);
        }
    }

    public function getAccountInfo()
    {
        $response = $this->client->get('https://www.blackfire.eu/en-gb/profile/', [
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
        $response = $this->client->get('https://www.blackfire.eu/en-gb/about-us', [
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

            $category = [
                "name" => trim($link->text()),
                "link" => $link->getTag()->getAttribute("href")->getValue(),
                "subcategories" => []
            ];

            $subcategories = $c->find('ul.nav-lvl-3 > li.nav-item');

            foreach($subcategories as $s)
            {
                $link = $s->find('a.lvl-3-title');

                $category["subcategories"][] = [
                    "name" => trim($link->text()),
                    "link" => $link->getTag()->getAttribute("href")->getValue()
                ];
            }

            $categories[$category["name"]] = $category;
        }

        return $categories;
    }

    public function getProducts($subcategoryID)
    {
        if (!$subcategoryID) return [];

        $response = $this->client->get('https://www.blackfire.eu/get_csv.php?subcategory=' . $subcategoryID, [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $csv = Reader::createFromString($body);
        $csv->setHeaderOffset(0);
        $csv->setDelimiter(';');

        $records = Statement::create()->process($csv);

        $products = [];

        foreach($records as $record)
        {
            $product = $record;

            $startPos = strlen("https://www.blackfire.eu/img/");
            $length = strpos($record["Image URL"], "_") - $startPos;
            $product["ID"] = substr($record["Image URL"], $startPos, $length);

            $products[] = $product;
        }

        return $products;
    }

    public function getProduct($productID)
    {
        if (!$productID) return false;

        $response = $this->client->get('https://www.blackfire.eu/product.php?id=' . $productID, [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->loadStr($body);

        return [
            'name' => $dom->find('#content h1')->text(),
            'image' => 'https://www.blackfire.eu/' . $dom->find('#image')->getTag()->getAttribute("src")->getValue(),
            'description' => $dom->find('#tab-description p')->innerHtml(),
        ];
    }
}