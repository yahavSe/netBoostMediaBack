<?php

namespace App\Http\Controllers;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MongoDB\Client;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\TooManyRedirectsException;

class CrawlerController extends Controller
{
    protected $client;
    protected $collection;

    public function __construct()
    {
        $this->client = new Client(env('DB_URI'));
        $this->collection = $this->client->selectDatabase(env('DB_DATABASE'))->selectCollection('urls');
    }

    public function crawl(Request $request): JsonResponse
    {
        $filtersSearch = $request->input('filtersSearch');
        $urlSearch = $filtersSearch['urlSearch'];
        $depth = $filtersSearch['depth'] ?? 1;

        if (!$this->collectionExists()) {
            $this->createCollection();
        }
        $isExists = false;

        if (empty($urlSearch)) {
            $urls = $this->getAllUrls($depth);
            $urlsData = $urls;
        } else {
            $url = $this->getUrlByValue($urlSearch);

            if ($url) {
//                $urlsData = $this->getRelatedUrlsRecursive($urlSearch, $depth);
                $urlsData = $this->getAllUrls();
                $isExists = true;

            } else {
                $this->crawlAndSaveUrl($urlSearch, $depth, null);
                $urlsData = $this->getAllUrls();
            }

        }

        return response()->json([
            'urlList' => [
                'data' => $urlsData,
                'existingUrl' => $isExists
            ]
        ]);
    }

    protected function collectionExists(): bool
    {
        $database = $this->client->selectDatabase(env('DB_DATABASE'));
        $collections = $database->listCollections();
        foreach ($collections as $collection) {
            if ($collection->getName() === 'urls') {
                return true;
            }
        }
        return false;
    }

    protected function createCollection(): void
    {
        $database = $this->client->selectDatabase(env('DB_DATABASE'));
        $database->createCollection('urls');
    }

    protected function getAllUrls(): array
    {
        $query = [];
        return $this->collection->find($query)->toArray();
    }

    protected function getUrlByValue(string $url): ?array
    {
        $query = ['url' => $url];
        $document = $this->collection->findOne($query);
        return $document ? (array)$document : null;
    }

    protected function crawlAndSaveUrl($url, $depth, $parent)
    {
        if (!is_string($url)) {
            return response()->json(['error' => 'Invalid URL.'], 400);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Invalid URL format.'], 400);
        }

        if (Str::startsWith($url, '/')) {
            $baseUrl = env('BASE_URL');
            $url = $baseUrl . $url;
        }
        $httpClient = new HttpClient();
        try {
            $response = $httpClient->get($url);
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $mongoClient = new Client(env('DB_URI'));
                $collection = $mongoClient->selectDatabase(env('DB_DATABASE'))->selectCollection('urls');

                $collection->insertOne([
                    'url' => $url,
                    'time' => now(),
                    'parent' => $parent
                ]);

                if ($depth > 0) {
                    $body = $response->getBody()->getContents();
                    $urls = $this->extractUrls($body);
                    foreach ($urls as $childUrl) {
                        $this->crawlAndSaveUrl($childUrl, $depth - 1, $url);
                    }
                }
            }
        } catch (TooManyRedirectsException $e) {
        } catch (Exception $e) {
        } catch (GuzzleException $e) {
        }
    }

    protected function saveUrl(string $url, ?string $parent): void
    {
        $this->collection->insertOne([
            'url' => $url,
            'parent' => $parent,
            'time' => now(),
        ]);
    }

    protected function extractUrls(string $content): array
    {
        $crawler = new Crawler($content);
        return $crawler->filter('a')->extract(['href']);
    }

    protected function getRelatedUrlsRecursive($mainUrl, $depth ): array
    {
        error_log("depth" . $depth);
        if ($depth < 0) return [];
        $relatedUrls = $this->getRelatedUrls($mainUrl);

        return $relatedUrls;
    }

    protected function getRelatedUrls($mainUrl): array
    {
        $mainUrl = stripslashes($mainUrl);
        $query = ['parent' => $mainUrl];
        $relatedUrls = $this->collection->find($query)->toArray();
        if (empty($relatedUrls)) {
            return [];
        }
        foreach ($relatedUrls as &$relatedUrl) {
            $childUrls = $this->getRelatedUrls($relatedUrl['url']);
            $relatedUrl['children'] = $childUrls;
        }
        return $relatedUrls;
    }

    public function refresh(Request $request): JsonResponse
    {
        $filtersSearch = $request->input('filtersSearch');
        $urlSearch = $filtersSearch['urlSearch'];

        $this->deleteUrlAndChildren($urlSearch);

        return $this->crawl($request);
    }

    protected function deleteUrlAndChildren(string $url): void
    {
        $query = ['url' => $url];
        $this->collection->deleteOne($query);

        $query = ['parent' => $url];
        $this->collection->deleteMany($query);
    }


}
