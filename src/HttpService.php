<?php

namespace Blackfire;

use GuzzleHttp\Client;

class HttpService
{
    private $client;

    public function __construct($user, $password)
    {
        $this->client = new Client();

        $this->login($user, $password);
    }

    public function login($user, $password)
    {
        $response = $this->client->post('https://www.blackfire.eu/do_account.php', [
            'body' => [
                "account" => $user,
                "password" => $password,
                "login" => "Log+in+to+your+Account",
            ],
            'cookies' => true
        ]);

        $body = (string) $response->getBody();

        if (!strpos($body, "Logout"))
        {
            echo "Not logged in";
        }
        else
        {
            echo "Success!";
        }

        dump($response);
    }
}