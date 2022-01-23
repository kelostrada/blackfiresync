<?php

namespace Blackfire;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use \Db;
use PHPHtmlParser\Dom;
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
                    "Name" => "bf",
                    "Value" => $lastCookie["cookie"],
                    "Domain" => "www.blackfire.eu",
                    "Path" => "/",
                    "Max-Age" => "86400",
                    "Expires" => $lastCookie["expires"],
                    "Secure" => true,
                    "Discard" => false,
                    "HttpOnly" => true
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

        $response = $this->client->post('https://www.blackfire.eu/do_account.php', [
            'body' => [
                "account" => $user,
                "password" => $password,
                "login" => "Log+in+to+your+Account",
            ],
            'cookies' => $this->cookieJar
        ]);

        $cookies = $this->cookieJar->toArray();
        $cookies = array_filter($cookies, function($c) {
            return $c["Name"] == "bf";
        });
        reset($cookies);
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
                "expires" => $cookie["Expires"]
            ]);
        }
    }

    public function getAccountInfo()
    {
        $response = $this->client->get('https://www.blackfire.eu/account.php?act=account', [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->loadStr($body);

        $accountData = $dom->find('#content .content .left p');

        return [
            "id" => trim($accountData[0]->getChildren()[1]->text())
        ];
    }

    public function getCategories()
    {
        // use contact page, as home page loads really slowly
        $response = $this->client->get('https://www.blackfire.eu/info.php?txt=contact', [
            'cookies' => $this->cookieJar
        ]);

        $body = (string) $response->getBody();

        $dom = new Dom;
        $dom->loadStr($body);

        $categoriesDom = $dom->find('#ctabs li a');

        $categories = [];

        foreach($categoriesDom as $c)
        {
            $href = $c->getTag()->getAttribute("href")->getValue();

            $category = [
                "id" => substr($href, 9),
                "name" => $c->text(),
                "link" => $href,
                "subcategories" => []
            ];

            $subcategories = $dom->find($category['link'] . ' li a');

            foreach($subcategories as $s)
            {
                $href = $s->getTag()->getAttribute("href")->getValue();

                if (strpos($href, "list.php?subcategory=") === false) continue;

                $category["subcategories"][] = [
                    "name" => $s->text(),
                    "id" => explode("=", $href)[1]
                ];
            }

            $categories[$category["id"]] = $category;
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
}