<?php

namespace App\Controllers;

use App\Auth;
use App\Config;
use \Core\View;
use \App\Models\Chat;
use \App\Models\UserInfo;


/**
 * Items controller (example)
 *
 * PHP version 7.0
 */
//class Items extends \Core\Controller
class App extends Authenticated
{

    /**
     * App index
     *
     * @return void
     */
    public function indexAction()
    {
        $chat = new Chat($_POST);
        $chats = $chat->getall();


        View::renderTemplate('App/index.html', [
            'chats' => $chats
        ]);

        Auth::setLastChatId($chat->getLastChatId());
    }

    /**
     * Gets new chats (for AJAX)
     *
     * @return void
     */
    public function getAction()
    {

        if(Auth::hasLastChatId())
        {

                $_POST['last_chat_id'] = Auth::getLastChatId();
                $chat = new Chat($_POST);

                if ($chat->hasChatAfter())
                {
                    $chats = $chat->getAfter(Auth::getLastChatId());

                    View::renderTemplate('App/chat.html', [
                        'chats' => $chats
                    ]);

                    Auth::setLastChatId($chat->getLastChatId());
                }

        }
        else
        {
            Auth::setLastChatId(0);

        }
    }

    /**
     * Send the typed chat (for AJAX)
     *
     * @return void
     */
    public function sendAction()
    {
        $_POST['email'] = Auth::getEmail();
        $chat = new Chat($_POST);
        $chat->send();;

    }

    /**
     * Gets new chats (for AJAX)
     *
     * @return void
     */
    public function updatelocationAction()
    {
        $userInfo = new UserInfo($_POST);
        if ($userInfo->hasIPUpdated())
        {
            $userInfo->saveIP();
            $userInfo->findLocationFromIP();
            $userInfo->saveLocation();
        }
    }

    public function testAction()
    {
        // Initialize CURL
        $_SERVER['REMOTE_ADDR'] = '134.201.250.155';
        echo $_SERVER['REMOTE_ADDR'];
        $ch = curl_init('http://api.ipstack.com/' . $_SERVER['REMOTE_ADDR'] . '?access_key=' . Config::IPSTACK_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Store the data
        $json = curl_exec($ch);
        curl_close($ch);

        //Decode JSON response
        $api_result = json_decode($json, true);

        echo $api_result['latitude'];

        //$this->long = $api_result['longitude'];
    }

    public function getLocationsAction()
    {
        $userInfo = new UserInfo($_POST);
        $locations = $userInfo->getAllLocations();
        echo json_encode($locations);


        //print_r($userInfo->getAllLocations());
        //echo $locations[1]['lat'];

    }


}
