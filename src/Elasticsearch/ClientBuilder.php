<?php

declare(strict_types = 1);

namespace Elasticsearch;

use Elasticsearch\Common\Exceptions\InvalidArgumentException;
use Elasticsearch\Common\Exceptions\RuntimeException;
use Elasticsearch\Common\Exceptions\ElasticCloudIdParseException;
use Elasticsearch\Common\Exceptions\AuthenticationConfigException;
use Elasticsearch\ConnectionPool\AbstractConnectionPool;
use Elasticsearch\ConnectionPool\Selectors\RoundRobinSelector;
use Elasticsearch\ConnectionPool\Selectors\SelectorInterface;
use Elasticsearch\ConnectionPool\StaticNoPingConnectionPool;
use Elasticsearch\Connections\Connection;
use Elasticsearch\Connections\ConnectionFactory;
use Elasticsearch\Connections\ConnectionFactoryInterface;
use Elasticsearch\Namespaces\NamespaceBuilderInterface;
use Elasticsearch\Serializers\SerializerInterface;
use Elasticsearch\ConnectionPool\Selectors;
use Elasticsearch\Serializers\SmartSerializer;
use GuzzleHttp\Ring\Client\CurlHandler;
use GuzzleHttp\Ring\Client\CurlMultiHandler;
use GuzzleHttp\Ring\Client\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ClientBuilder
 *
 * @category Elasticsearch
 * @package  Elasticsearch\Common\Exceptions
 * @author   Zachary Tong <zach@elastic.co>
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache2
 * @link     http://elastic.co
 */
class ClientBuilder
{
    /**
     * @var Transport
     */
    private $transport;

    /**
     * @var callable
     */
    private $endpoint;

    /**
     * @var NamespaceBuilderInterface[]
     */
    private $registeredNamespacesBuilders = [];

    /**
     * @var ConnectionFactoryInterface
     */
    private $connectionFactory;

    private $handler;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoggerInterface
     */
    private $tracer;

    /**
     * @var string
     */
    private $connectionPool = StaticNoPingConnectionPool::class;

    /**
     * @var string
     */
    private $serializer = SmartSerializer::class;

    /**
     * @var string
     */
    private $selector = RoundRobinSelector::class;

    /**
     * @var array
     */
    private $connectionPoolArgs = [
        'randomizeHosts' => true
    ];

    /**
     * @var array
     */
    private $hosts;

    /**
     * @var array
     */
    private $connectionParams;

    /**
     * @var int
     */
    private $retries;

    /**
     * @var bool
     */
    private $sniffOnStart = false;

    /**
     * @var null|array
     */
    private $sslCert = null;

    /**
     * @var null|array
     */
    private $sslKey = null;

    /**
     * @var null|bool|string
     */
    private $sslVerification = null;

    public static function create(): ClientBuilder
    {
        return new static();
    }

    /**
     * Can supply first parm to Client::__construct() when invoking manually or with dependency injection
     */
    public function getTransport(): Transport
    {
        return $this->transport;
    }

    /**
     * Can supply second parm to Client::__construct() when invoking manually or with dependency injection
     */
    public function getEndpoint(): callable
    {
        return $this->endpoint;
    }

    /**
     * Can supply third parm to Client::__construct() when invoking manually or with dependency injection
     *
     * @return NamespaceBuilderInterface[]
     */
    public function getRegisteredNamespacesBuilders(): array
    {
        return $this->registeredNamespacesBuilders;
    }

    /**
     * Build a new client from the provided config.  Hash keys
     * should correspond to the method name e.g. ['connectionPool']
     * corresponds to setConnectionPool().
     *
     * Missing keys will use the default for that setting if applicable
     *
     * Unknown keys will throw an exception by default, but this can be silenced
     * by setting `quiet` to true
     *
     * @param  bool $quiet False if unknown settings throw exception, true to silently
     *                     ignore unknown settings
     * @throws Common\Exceptions\RuntimeException
     */
    public static function fromConfig(array $config, bool $quiet = false): Client
    {
        $builder = new self;
        foreach ($config as $key => $value) {
            $method = "set$key";
            if (method_exists($builder, $method)) {
                $builder->$method($value);
                unset($config[$key]);
            }
        }

        if ($quiet === false && count($config) > 0) {
            $unknown = implode(array_keys($config));
            throw new RuntimeException("Unknown parameters provided: $unknown");
        }
        return $builder->build();
    }

    /**
     * @throws \RuntimeException
     */
    public static function defaultHandler(array $multiParams = [], array $singleParams = []): callable
    {
        $future = null;
        if (extension_loaded('curl')) {
            $config = array_merge([ 'mh' => curl_multi_init() ], $multiParams);
            if (function_exists('curl_reset')) {
                $default = new CurlHandler($singleParams);
                $future = new CurlMultiHandler($config);
            } else {
                $default = new CurlMultiHandler($config);
            }
        } else {
            throw new \RuntimeException('Elasticsearch-PHP requires cURL, or a custom HTTP handler.');
        }

        return $future ? Middleware::wrapFuture($default, $future) : $default;
    }

    /**
     * @throws \RuntimeException
     */
    public static function multiHandler(array $params = []): CurlMultiHandler
    {
        if (function_exists('curl_multi_init')) {
            return new CurlMultiHandler(array_merge([ 'mh' => curl_multi_init() ], $params));
        } else {
            throw new \RuntimeException('CurlMulti handler requires cURL.');
        }
    }

    /**
     * @throws \RuntimeException
     */
    public static function singleHandler(): CurlHandler
    {
        if (function_exists('curl_reset')) {
            return new CurlHandler();
        } else {
            throw new \RuntimeException('CurlSingle handler requires cURL.');
        }
    }

    public function setConnectionFactory(ConnectionFactoryInterface $connectionFactory): ClientBuilder
    {
        $this->connectionFactory = $connectionFactory;

        return $this;
    }

    /**
     * @param  AbstractConnectionPool|string $connectionPool
     * @throws \InvalidArgumentException
     */
    public function setConnectionPool($connectionPool, array $args = []): ClientBuilder
    {
        if (is_string($connectionPool)) {
            $this->connectionPool = $connectionPool;
            $this->connectionPoolArgs = $args;
        } elseif (is_object($connectionPool)) {
            $this->connectionPool = $connectionPool;
        } else {
            throw new InvalidArgumentException("Serializer must be a class path or instantiated object extending AbstractConnectionPool");
        }

        return $this;
    }

    public function setEndpoint(callable $endpoint): ClientBuilder
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function registerNamespace(NamespaceBuilderInterface $namespaceBuilder): ClientBuilder
    {
        $this->registeredNamespacesBuilders[] = $namespaceBuilder;

        return $this;
    }

    public function setTransport(Transport $transport): ClientBuilder
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * @param  mixed $handler
     * @return $this
     */
    public function setHandler($handler): ClientBuilder
    {
        $this->handler = $handler;

        return $this;
    }

    public function setLogger(LoggerInterface $logger): ClientBuilder
    {
        if (!$logger instanceof LoggerInterface) {
            throw new InvalidArgumentException('$logger must implement \Psr\Log\LoggerInterface!');
        }

        $this->logger = $logger;

        return $this;
    }

    public function setTracer(LoggerInterface $tracer): ClientBuilder
    {
        if (!$tracer instanceof LoggerInterface) {
            throw new InvalidArgumentException('$tracer must implement \Psr\Log\LoggerInterface!');
        }

        $this->tracer = $tracer;

        return $this;
    }

    /**
     * @param \Elasticsearch\Serializers\SerializerInterface|string $serializer
     */
    public function setSerializer($serializer): ClientBuilder
    {
        $this->parseStringOrObject($serializer, $this->serializer, 'SerializerInterface');

        return $this;
    }

    public function setHosts(array $hosts): ClientBuilder
    {
        $this->hosts = $hosts;

        return $this;
    }

    /**
     * Set the APIKey Pair, consiting of the API Id and the ApiKey of the Response from /_security/api_key
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/security-api-create-api-key.html
     *
     * @throws Elasticsearch\Common\Exceptions\AuthenticationConfigException
     */
    public function setApiKey(string $id, string $apiKey): ClientBuilder
    {
        if (isset($this->connectionParams['client']['curl'][CURLOPT_HTTPAUTH]) === true) {
            throw new AuthenticationConfigException("You can't use APIKey - and Basic Authenication together.");
        }

        $this->connectionParams['client']['headers']['Authorization'] = [
            'ApiKey ' . base64_encode($id . ':' . $apiKey)
        ];

        return $this;
    }

    /**
     * Set the APIKey Pair, consiting of the API Id and the ApiKey of the Response from /_security/api_key
     *
     * @param string $username
     * @param string $password
     *
     * @throws Elasticsearch\Common\Exceptions\AuthenticationConfigException
     */
    public function setBasicAuthentication(string $username, string $password): ClientBuilder
    {
        if (isset($this->connectionParams['client']['headers']['Authorization']) === true) {
            throw new AuthenticationConfigException("You can't use APIKey - and Basic Authenication together.");
        }

        if (isset($this->connectionParams['client']['curl']) === false) {
            $this->connectionParams['client']['curl'] = [];
        }

        $this->connectionParams['client']['curl'] += [
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD  => $username.':'.$password
        ];

        return $this;
    }

    /**
     * Set Elastic Cloud ID to connect to Elastic Cloud
     *
     * @link  https://elastic.co/cloud
     *
     * @param string $cloudId
     */
    public function setElasticCloudId(string $cloudId): ClientBuilder
    {
        // Register the Hosts array
        $this->setHosts([
            [
                'host'   => $this->parseElasticCloudId($cloudId),
                'port'   => '',
                'scheme' => 'https',
            ]
        ]);

        // Merge best practices for the connection
        $this->setConnectionParams([
            'client' => [
                'curl' => [
                    CURLOPT_ENCODING => 1,
                ],
            ]
        ]);

        return $this;
    }

    public function setConnectionParams(array $params): ClientBuilder
    {
        $this->connectionParams = $params;

        return $this;
    }

    public function setRetries(int $retries): ClientBuilder
    {
        $this->retries = $retries;

        return $this;
    }

    /**
     * @param \Elasticsearch\ConnectionPool\Selectors\SelectorInterface|string $selector
     */
    public function setSelector($selector): ClientBuilder
    {
        $this->parseStringOrObject($selector, $this->selector, 'SelectorInterface');

        return $this;
    }

    public function setSniffOnStart(bool $sniffOnStart): ClientBuilder
    {
        $this->sniffOnStart = $sniffOnStart;

        return $this;
    }

    /**
     * @param string $cert The name of a file containing a PEM formatted certificate.
     */
    public function setSSLCert(string $cert, string $password = null): ClientBuilder
    {
        $this->sslCert = [$cert, $password];

        return $this;
    }

    /**
     * @param string $key The name of a file containing a private SSL key.
     */
    public function setSSLKey(string $key, string $password = null): ClientBuilder
    {
        $this->sslKey = [$key, $password];

        return $this;
    }

    /**
     *  @param bool|string $value
     */
    public function setSSLVerification($value = true): ClientBuilder
    {
        $this->sslVerification = $value;

        return $this;
    }

    public function build(): Client
    {
        $this->buildLoggers();

        if (is_null($this->handler)) {
            $this->handler = ClientBuilder::defaultHandler();
        }

        $sslOptions = null;
        if (isset($this->sslKey)) {
            $sslOptions['ssl_key'] = $this->sslKey;
        }
        if (isset($this->sslCert)) {
            $sslOptions['cert'] = $this->sslCert;
        }
        if (isset($this->sslVerification)) {
            $sslOptions['verify'] = $this->sslVerification;
        }

        if (!is_null($sslOptions)) {
            $sslHandler = function (callable $handler, array $sslOptions) {
                return function (array $request) use ($handler, $sslOptions) {
                    // Add our custom headers
                    foreach ($sslOptions as $key => $value) {
                        $request['client'][$key] = $value;
                    }

                    // Send the request using the handler and return the response.
                    return $handler($request);
                };
            };
            $this->handler = $sslHandler($this->handler, $sslOptions);
        }

        if (is_null($this->serializer)) {
            $this->serializer = new SmartSerializer();
        } elseif (is_string($this->serializer)) {
            $this->serializer = new $this->serializer;
        }

        if (is_null($this->connectionFactory)) {
            if (is_null($this->connectionParams)) {
                $this->connectionParams = [];
            }

            // Make sure we are setting Content-Type and Accept (unless the user has explicitly
            // overridden it
            if (! isset($this->connectionParams['client']['headers'])) {
                $this->connectionParams['client']['headers'] = [];
            }
            if (! isset($this->connectionParams['client']['headers']['Content-Type'])) {
                $this->connectionParams['client']['headers']['Content-Type'] = ['application/json'];
            }
            if (! isset($this->connectionParams['client']['headers']['Accept'])) {
                $this->connectionParams['client']['headers']['Accept'] = ['application/json'];
            }

            $this->connectionFactory = new ConnectionFactory($this->handler, $this->connectionParams, $this->serializer, $this->logger, $this->tracer);
        }

        if (is_null($this->hosts)) {
            $this->hosts = $this->getDefaultHost();
        }

        if (is_null($this->selector)) {
            $this->selector = new RoundRobinSelector();
        } elseif (is_string($this->selector)) {
            $this->selector = new $this->selector;
        }

        $this->buildTransport();

        if (is_null($this->endpoint)) {
            $serializer = $this->serializer;

            $this->endpoint = function ($class) use ($serializer) {
                $fullPath = '\\Elasticsearch\\Endpoints\\' . $class;
                if ($class === 'Bulk' || $class === 'Msearch' || $class === 'MsearchTemplate' || $class === 'MPercolate') {
                    return new $fullPath($serializer);
                } else {
                    return new $fullPath();
                }
            };
        }

        $registeredNamespaces = [];
        foreach ($this->registeredNamespacesBuilders as $builder) {
            /**
 * @var NamespaceBuilderInterface $builder
*/
            $registeredNamespaces[$builder->getName()] = $builder->getObject($this->transport, $this->serializer);
        }

        return $this->instantiate($this->transport, $this->endpoint, $registeredNamespaces);
    }

    protected function instantiate(Transport $transport, callable $endpoint, array $registeredNamespaces): Client
    {
        return new Client($transport, $endpoint, $registeredNamespaces);
    }

    private function buildLoggers(): void
    {
        if (is_null($this->logger)) {
            $this->logger = new NullLogger();
        }

        if (is_null($this->tracer)) {
            $this->tracer = new NullLogger();
        }
    }

    private function buildTransport(): void
    {
        $connections = $this->buildConnectionsFromHosts($this->hosts);

        if (is_string($this->connectionPool)) {
            $this->connectionPool = new $this->connectionPool(
                $connections,
                $this->selector,
                $this->connectionFactory,
                $this->connectionPoolArgs
            );
        } elseif (is_null($this->connectionPool)) {
            $this->connectionPool = new StaticNoPingConnectionPool(
                $connections,
                $this->selector,
                $this->connectionFactory,
                $this->connectionPoolArgs
            );
        }

        if (is_null($this->retries)) {
            $this->retries = count($connections);
        }

        if (is_null($this->transport)) {
            $this->transport = new Transport($this->retries, $this->connectionPool, $this->logger, $this->sniffOnStart);
        }
    }

    private function parseStringOrObject($arg, &$destination, $interface): void
    {
        if (is_string($arg)) {
            $destination = new $arg;
        } elseif (is_object($arg)) {
            $destination = $arg;
        } else {
            throw new InvalidArgumentException("Serializer must be a class path or instantiated object implementing $interface");
        }
    }

    private function getDefaultHost(): array
    {
        return ['localhost:9200'];
    }

    /**
     * @return \Elasticsearch\Connections\Connection[]
     * @throws RuntimeException
     */
    private function buildConnectionsFromHosts(array $hosts): array
    {
        $connections = [];
        foreach ($hosts as $host) {
            if (is_string($host)) {
                $host = $this->prependMissingScheme($host);
                $host = $this->extractURIParts($host);
            } elseif (is_array($host)) {
                $host = $this->normalizeExtendedHost($host);
            } else {
                $this->logger->error("Could not parse host: ".print_r($host, true));
                throw new RuntimeException("Could not parse host: ".print_r($host, true));
            }

            $connections[] = $this->connectionFactory->create($host);
        }

        return $connections;
    }

    /**
     * @throws RuntimeException
     */
    private function normalizeExtendedHost(array $host): array
    {
        if (isset($host['host']) === false) {
            $this->logger->error("Required 'host' was not defined in extended format: ".print_r($host, true));
            throw new RuntimeException("Required 'host' was not defined in extended format: ".print_r($host, true));
        }

        if (isset($host['scheme']) === false) {
            $host['scheme'] = 'http';
        }
        if (isset($host['port']) === false) {
            $host['port'] = 9200;
        }
        return $host;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function extractURIParts(string $host): array
    {
        $parts = parse_url($host);

        if ($parts === false) {
            throw new InvalidArgumentException("Could not parse URI");
        }

        if (isset($parts['port']) !== true) {
            $parts['port'] = 9200;
        }

        return $parts;
    }

    private function prependMissingScheme(string $host): string
    {
        if (!filter_var($host, FILTER_VALIDATE_URL)) {
            $host = 'http://' . $host;
        }

        return $host;
    }

    /**
     * Parse the Elastic Cloud Params from the CloudId
     *
     * @param string $cloudId
     *
     * @return string
     *
     * @throws ElasticCloudIdParseException
     */
    private function parseElasticCloudId(string $cloudId): string
    {
        try {
            list($name, $encoded) = explode(':', $cloudId);
            list($uri, $uuids)    = explode('$', base64_decode($encoded));
            list($es,)            = explode(':', $uuids);

            return $es . '.' . $uri;
        } catch (\Throwable $t) {
            throw new ElasticCloudIdParseException('could not parse the Cloud ID:' . $cloudId);
        }
    }
}
