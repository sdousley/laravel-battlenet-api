<?php

namespace Xklusive\BattlenetApi;

use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository;

/**
 * @author Guillaume Meheust <xklusive91@gmail.com>
 */
class BattlenetHttpClient
{
    /**
     * @var Repository
     */
    protected $cache;

    /**
     * @var string
     */
    protected $cacheKey = 'xklusive.battlenetapi.cache';

    /**
     * Http client.
     *
     * @var object GuzzleHttp\Client
     */
    protected $client;

    /**
     * Game name for url prefix.
     *
     * @var string
     */
    protected $gameParam;

    /**
     * Battle.net Connection Options.
     *
     * @var Collection
     */
    protected $options;

    /**
     * BattlnetHttpClient constructor.
     */
    public function __construct(Repository $repository)
    {
        $this->cache = $repository;
        $this->client = new Client([
            'base_uri' => $this->getApiEndPoint(),
        ]);
    }

    /**
     * Make request with API url and specific URL suffix.
     *
     * @return Collection|ClientException
     */
    protected function api()
    {
        $maxAttempts = 0;
        $attempts = 0;

        $statusCodes = [
            // Currently all status codes except the 503 is disabled and not handled
            '504' => [
                'message' => 'Gateway Timeout',
                'retry' => 6,
            ],
        ];

        do {
            try {
                $response = $this->client->get($this->apiEndPoint, $this->options->toArray());

                return collect(json_decode($response->getBody()->getContents()));
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                $reasonPhrase = $e->getResponse()->getReasonPhrase();

                if (array_key_exists($statusCode, $statusCodes)) {
                    if ($statusCodes[$statusCode]['message'] == $reasonPhrase) {
                        $maxAttempts = $statusCodes[$statusCode]['retry'];
                        $attempts++;
                        continue;
                    }
                }
            } catch (RequestException $e) {
                // @TODO: Handle the RequestException ( when the provided domain is not valid )
            }
        } while ($attempts < $maxAttempts);

        throw $e;
    }

    /**
     * Cache the api response data if cache set to true in config file.
     *
     * @param array  $options   Options
     * @param  string $method   method name
     * @param string $apiEndPoint
     * @return Collection|ClientException
     */
    public function cache($apiEndPoint, array $options, $method)
    {
        // Make sure the options we got is a collection
        $options = $this->wrapCollection($options);

        $this->options = $this->getQueryOptions($options);
        $this->apiEndPoint = $this->gameParam.$apiEndPoint;

        $this->buildCahceOptions($method);

        if ($this->options->has('cache')) {
            // The cache options are defined we need to cache the results
            return $this->cache->remember(
                $this->options->get('cache')->get('uniqKey'),
                $this->options->get('cache')->get('duration'),
                function () {
                    return $this->api();
                }
            );
        }

        return $this->api();
    }

    /**
     * Get default query options from configuration file.
     *
     * @return Collection
     */
    private function getDefaultOptions()
    {
        return collect([
            'locale' => $this->getLocale(),
            'apikey' => $this->getApiKey(),
        ]);
    }

    /**
     * Set default option if a 'query' key is provided
     * else create 'query' key with default options.
     *
     * @param Collection $options
     *
     * @return Collection api response
     */
    private function getQueryOptions(Collection $options)
    {
        // Make sure the query object is a collection.
        $query = $this->wrapCollection($options->get('query'));

        foreach ($this->getDefaultOptions() as $key => $option) {
            if ($query->has($key) === false) {
                $query->put($key, $option);
            }
        }

        $options->put('query', $query);

        return $options;
    }

    /**
     * Get API domain provided in configuration.
     *
     * @return string
     */
    private function getApiEndPoint()
    {
        return config('battlenet-api.domain');
    }

    /**
     * Get API key provided in configuration.
     *
     * @return string
     */
    private function getApiKey()
    {
        return config('battlenet-api.api_key');
    }

    /**
     * Get API locale provided in configuration.
     *
     * @return string
     */
    private function getLocale()
    {
        return config('battlenet-api.locale', 'eu');
    }

    /**
     * This method wraps the given value in a collection when applicable.
     *
     * @return Collection
     */
    public function wrapCollection($collection)
    {
        if (is_a($collection, Collection::class) === true) {
            return $collection;
        }

        return collect($collection);
    }

    /**
     * Build the cache configuration.
     *
     * @param string $method
     */
    private function buildCahceOptions($method)
    {
        if (config('battlenet-api.cache', true)) {
            if ($this->options->has('cache') === false) {
                // We don't have any cache options yet, build it from ground up.
                $cacheOptions = collect();

                $cacheOptions->put('method', snake_case($method));
                $cacheOptions->put('uniqKey', implode('.', [$this->cacheKey, $cacheOptions->get('method')]));
                $cacheOptions->put('duration', config('battlenet-api.cache_duration', 600));

                $this->options->put('cache', $cacheOptions);
            }
        }
    }
}
