<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Recall\Http\Controller\CardsController;
use Recall\Http\Controller\NotesController;
use Recall\Http\Controller\ReviewsController;
use Recall\Http\Controller\StatsController;
use Recall\Http\Json;
use Recall\Http\Serializer;
use Recall\Http\ValidationException;
use Recall\Infrastructure\Persistence\CardRepository;
use Recall\Infrastructure\Persistence\NoteRepository;
use Recall\Infrastructure\Persistence\Orm;
use Recall\Infrastructure\Persistence\QueryCounter;
use Recall\Infrastructure\Persistence\ReviewRepository;
use Recall\Infrastructure\Persistence\Seeder;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$envDb = getenv('RECALL_DB');
$databasePath = $envDb === false || $envDb === '' ? dirname(__DIR__) . '/var/recall.sqlite' : $envDb;
$now = new DateTimeImmutable('now');

$boot = Orm::boot($databasePath);
$queries = new QueryCounter();
$driver = $boot->dbal->driver('sqlite');
if ($driver instanceof LoggerAwareInterface) {
    $driver->setLogger($queries);
}

$noteRepo = new NoteRepository($boot->orm);
$cardRepo = new CardRepository($boot->orm);
$reviewRepo = new ReviewRepository($boot->orm);

(new Seeder($noteRepo, $cardRepo))->seedIfEmpty($now);

$serializer = new Serializer();
$notes = new NotesController($noteRepo, $serializer, $now);
$cards = new CardsController($cardRepo, $noteRepo, $serializer, $now);
$reviews = new ReviewsController($cardRepo, $reviewRepo, $serializer, $now);
$stats = new StatsController($cardRepo, $reviewRepo, $serializer);

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// CORS — фронтенд-дев-сервер ходит к нам с другого источника.
$app->add(static fn(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => $handler->handle($request)
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
    ->withHeader('Access-Control-Allow-Headers', 'Content-Type'));

// Метрики: время запроса и число запросов к БД (наблюдение, не гейт).
$app->add(static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($queries): ResponseInterface {
    $start = hrtime(true);
    $response = $handler->handle($request);

    return $response
        ->withHeader('Server-Timing', sprintf('app;dur=%.1f', (hrtime(true) - $start) / 1e6))
        ->withHeader('X-DB-Query-Count', (string) $queries->count());
});

$app->get('/notes', $notes->index(...));
$app->post('/notes', $notes->create(...));
$app->get('/notes/{id}', $notes->show(...));
$app->put('/notes/{id}', $notes->update(...));
$app->delete('/notes/{id}', $notes->delete(...));

$app->get('/cards', $cards->index(...));
$app->post('/cards', $cards->create(...));
$app->get('/cards/{id}', $cards->show(...));
$app->delete('/cards/{id}', $cards->delete(...));

$app->get('/reviews/queue', $reviews->queue(...));
$app->post('/reviews/{id}', $reviews->grade(...));

$app->get('/stats', $stats->index(...));

$app->options('/{routes:.+}', static fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withStatus(204));

$app->addRoutingMiddleware();

$errors = $app->addErrorMiddleware(false, false, false);
$errors->setDefaultErrorHandler(
    static function (ServerRequestInterface $request, Throwable $exception) use ($app): ResponseInterface {
        $response = $app->getResponseFactory()->createResponse();

        return match (true) {
            $exception instanceof HttpNotFoundException => Json::error($response, 'route not found', 404),
            $exception instanceof HttpMethodNotAllowedException => Json::error($response, 'method not allowed', 405),
            $exception instanceof ValidationException => Json::error($response, $exception->getMessage(), 422, $exception->details()),
            $exception instanceof InvalidArgumentException => Json::error($response, $exception->getMessage(), 422),
            default => Json::error($response, 'internal error', 500),
        };
    },
);

$app->run();
