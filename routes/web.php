<?php

/** @var Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

use Laravel\Lumen\Routing\Router;

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->group(['prefix' => 'v1', 'middleware' => 'track.api'], function () use ($router) {
    $router->post('/chat/completions', 'OpenAIController@chatCompletions');
    $router->post('/completions', 'OpenAIController@completions');
});

$logger = app(\App\Services\FileLogger::class);
$stats = $logger->getUserStats(1);
