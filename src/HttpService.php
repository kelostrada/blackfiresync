<?php

namespace Blackfire;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use \Db;
use PHPHtmlParser\Dom;

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
            echo "Not logged in";
        }
        else
        {
            echo "Success!";

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
}