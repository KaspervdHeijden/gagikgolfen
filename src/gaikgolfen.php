#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace KasH\GaIkGolfen;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Exception;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

use function base64_decode;
use function basename;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function file_get_contents;
use function file_put_contents;
use function fputcsv;
use function fwrite;
use function get_class;
use function getopt;
use function in_array;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function libxml_use_internal_errors;
use function ltrim;
use function parse_url;
use function sprintf;
use function str_repeat;
use function strlen;
use function strpos;
use function trim;

use const PHP_URL_QUERY;

(new class (getopt('v', ['login:', 'passwd:', 'columns::', 'date::', 'cache::', 'display::', 'course::']))
{
    private const TEE_TIMES_URL          = 'https://www.ikgagolfen.nl/asparagi/ikgagolfen/site2/teetimes/teetimes.asp?%s';
    private const LOGIN_FAILED_NEEDLE    = 'Achternaam of wachtwoord is onjuist';
    private const DOMAIN_NAME            = 'https://www.ikgagolfen.nl/';
    private const AVAILABLE_CLASSNAMES   = ['tt_av', 'tt_avh'];
    private const COLUMN_TITLE_ID_FORMAT = 'crltitle%d';
    private const DATE_FORMAT            = 'd/m/Y';
    private const POST_FIELDS_FORMAT     = '_name=%s&_ww=%s';
    private const FORM_LOGIN_NAME        = 'login';
    private const COLUMN_ID_FORMAT       = 'ts%d';

    private const USER_AGENT             = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:85.0) Gecko/20100101 Firefox/85.0';
    private const MAX_TIMEOUT            = 20;
    private const ERROR_RESULT           = 1;

    private const DEFAULT_DATE           = 'tomorrow';
    private const DISPLAY_TABLE          = 'table';
    private const DISPLAY_CSV            = 'csv';
    private const DEFAULT_DISPLAY        = self::DISPLAY_CSV;
    private const DEFAULT_COLUMNS        = 4;

    private array $columnTextCache = [];
    private array $config;

    public function __construct($arguments)
    {
        if (! is_array($arguments)) {
            throw new RuntimeException('Could not parse arguments');
        }

        if (($arguments['login'] ?? '') === '') {
            throw new InvalidArgumentException('Invalid login');
        }

        if (($arguments['passwd'] ?? '') === '') {
            throw new InvalidArgumentException('Invalid password');
        }

        $passwdDecoded = base64_decode($arguments['passwd']);
        if (! is_string($passwdDecoded)) {
            throw new InvalidArgumentException('Could not parse password');
        }

        $columns = (int)($arguments['columns'] ?? self::DEFAULT_COLUMNS);
        if ($columns < 1) {
            throw new InvalidArgumentException("Invalid number of columns: {$columns}.");
        }

        $dateString = (string)($arguments['date'] ?? self::DEFAULT_DATE);
        try {
            if ($dateString === '') {
                throw new UnexpectedValueException('Invalid date');
            }

            $date = new DateTimeImmutable($dateString);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(
                "Could not parse date '{$dateString}'",
                $exception->getCode(),
                $exception
            );
        }

        $this->config = [
            'display' => (string)($arguments['display'] ?? self::DEFAULT_DISPLAY),
            'course'  => (string)($arguments['course'] ?? ''),
            'date'    => (string)$date->format(self::DATE_FORMAT),
            'cache'   => (string)($arguments['cache'] ?? ''),
            'verbose' => (bool)isset($arguments['v']),
            'login'   => (string)$arguments['login'],
            'passwd'  => (string)$passwdDecoded,
            'columns' => $columns,
        ];

        libxml_use_internal_errors(true);
    }

    private function curlAction(string $url, ?string $postFields): string
    {
        try {
            $curl = curl_init($url);
            if (! $curl) {
                throw new RuntimeException('Could not initialize curl');
            }

            $options = [
                CURLOPT_TIMEOUT        => self::MAX_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_RETURNTRANSFER => true,
            ];


            if ($postFields !== null) {
                $options[CURLOPT_POSTFIELDS] = $postFields;
                $options[CURLOPT_POST]       = true;
            }

            curl_setopt_array($curl, $options);
            $response = curl_exec($curl);

            if (! is_string($response)) {
                throw new UnexpectedValueException(
                    ((string)curl_error($curl)) ?: "Something went wrong downloading '{$url}'",
                    (int)curl_errno($curl) ?: 1
                );
            }

            $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($statusCode > 299) {
                throw new UnexpectedValueException("Expected a 200 result, got {$statusCode}");
            }

            return $response;
        } finally {
            if ($curl) {
                curl_close($curl);
            }
        }
    }

    private function downloadPage(string $url, ?string $postFields): DOMDocument
    {
        $html     = $this->curlAction($url, $postFields);
        $document = new DOMDocument();

        if (! $document->loadHTML($html)) {
            throw new RuntimeException('Could not load HTML');
        }

        return $document;
    }

    private function getLoginForm(DOMDocument $document): ?DOMElement
    {
        /** @var DOMElement[] $forms */
        $forms = $document->getElementsByTagName('form');
        foreach ($forms as $form) {
            if ((string)$form->getAttribute('name') !== self::FORM_LOGIN_NAME) {
                continue;
            }

            if ((string)$form->getAttribute('action') === '') {
                continue;
            }

            return $form;
        }

        return null;
    }

    private function getLoginTargetUrl(string $domain): string
    {
        $document  = $this->downloadPage($domain, null);
        $loginForm = $this->getLoginForm($document);

        if (! $loginForm instanceof DOMElement) {
            throw new LogicException('Could not determine login target URL');
        }

        $action = (string)$loginForm->getAttribute('action');
        if (strpos($action, $domain) !== 0) {
            return $domain . ltrim($action, '//');
        }

        return $action;
    }

    private function getColumn(DOMDocument $document, int $columnIndex): ?DOMElement
    {
        return $document->getElementById(sprintf(self::COLUMN_ID_FORMAT, $columnIndex));
    }

    private function getSessionFromCache(string $cache): string
    {
        if ($cache === '' || ! is_file($cache) || ! is_readable($cache)) {
            return '';
        }

        $content = file_get_contents($cache);
        if (! is_string($content)) {
            throw new RuntimeException("Cannot read contents from cache file '{$cache}'");
        }

        return $content;
    }

    /** @noinspection PhpPureAttributeCanBeAddedInspection */
    private function wasLoginSuccesful(string $html): bool
    {
        if (str_contains($html, self::LOGIN_FAILED_NEEDLE)) {
            return false;
        }

        return true;
    }

    private function login(array $config, string $cache): string
    {
        $postFields = sprintf(self::POST_FIELDS_FORMAT, $config['login'], $config['passwd']);
        $loginUrl   = $this->getLoginTargetUrl(self::DOMAIN_NAME);
        $html       = $this->curlAction($loginUrl, $postFields);

        if (! $this->wasLoginSuccesful($html)) {
            throw new RuntimeException('Could not login', 10);
        }

        $query = (string)parse_url($loginUrl, PHP_URL_QUERY);
        if ($query === '') {
            throw new LogicException('Could not extract session variables: url format mismatch');
        }

        if ($cache !== '' && ! file_put_contents($cache, $query)) {
            throw new RuntimeException("Could not write to cache file '{$cache}'");
        }

        return $query;
    }

    private function println(string $string = '', bool $toErr = false): void
    {
        fwrite($toErr ? STDERR : STDOUT, $string . PHP_EOL);
    }

    private function outputAvailableTime(
        string $columnText,
        string $availableTimeText,
        string $display
    ): void {
        switch ($display) {
            case self::DISPLAY_CSV:
                fputcsv(STDOUT, [$columnText, $availableTimeText]);
                break;
            case self::DISPLAY_TABLE:
                if (! isset($this->columnTextCache[$columnText])) {
                    if (count($this->columnTextCache) > 0) {
                        $this->println();
                    }

                    $this->columnTextCache[$columnText] = 1;
                    $this->println($columnText);
                    $this->println(str_repeat('-', strlen($columnText)));
                }

                $this->println($availableTimeText);
                break;
            default:
                throw new InvalidArgumentException("Display not supported: '{$display}'");
        }
    }

    private function displayAvailableTimesForColumn(
        DOMDocument $document,
        int $columnIndex,
        string $display
    ): void {
        $column = $this->getColumn($document, $columnIndex);
        if (! $column instanceof DOMElement) {
            throw new UnexpectedValueException("Column {$columnIndex} not found");
        }

        $title = $document->getElementById(sprintf(self::COLUMN_TITLE_ID_FORMAT, $columnIndex));
        $text  = sprintf('Column %d', $columnIndex + 1);

        if ($title instanceof DOMElement) {
            $text = trim($title->textContent);
        }

        $tds = $column->getElementsByTagName('td');
        foreach ($tds as $td) {
            if (! in_array((string)$td->getAttribute('class'), self::AVAILABLE_CLASSNAMES)) {
                continue;
            }

            $this->outputAvailableTime($text, trim($td->textContent), $display);
        }
    }

    private function downloadTeeTimesDocument(
        string $sessionId,
        string $postData,
        bool $verbose
    ): ?DOMDocument {
        $url      = sprintf(self::TEE_TIMES_URL, $sessionId);
        $document = $this->downloadPage($url, $postData);
        $column   = $this->getColumn($document, 0);

        if (! $column instanceof DOMElement) {
            return null;
        }

        if ($verbose) {
            $this->println($url);
        }

        return $document;
    }

    private function getTeetimesDocument(
        string $postData,
        array $config,
        bool $verbose
    ): DOMDocument {
        $sessionId = $this->getSessionFromCache($config['cache']);
        if ($sessionId !== '') {
            $document = $this->downloadTeeTimesDocument($sessionId, $postData, $verbose);
            if ($document instanceof DOMDocument) {
                return $document;
            }
        }

        $sessionId = $this->login($config, $config['cache']);
        $document  = $this->downloadTeeTimesDocument($sessionId, $postData, $verbose);

        if (! $document instanceof DOMDocument) {
            throw new LogicException('Could not load teetimes page');
        }

        return $document;
    }

    private function displayAvailableTimes(array $config): void
    {
        $postData = "playdate={$config['date']}";
        if ($config['course'] !== '') {
            $postData .= "&_comnr1={$config['course']}";
        }

        $document = $this->getTeetimesDocument($postData, $config, $config['verbose']);
        for ($column = 0; $column < $config['columns']; ++$column) {
            $this->displayAvailableTimesForColumn($document, $column, $config['display']);
        }
    }

    private function showHelp(?Throwable $exception): void
    {
        if ($exception instanceof Throwable && $exception->getMessage() !== '') {
            $this->println(sprintf('%s: %s', get_class($exception), $exception->getMessage()), true);
            $this->println('', true);
        }

        $this->println('Usage:');
        $this->println(sprintf(
            '  %s --login=<login> --passwd=<password> [--columns=%d] [--date=%s] [--display=%s|%s] [--course=<course-id>] [--cache=<cache-file>]',
            basename(__FILE__),
            self::DEFAULT_COLUMNS,
            self::DEFAULT_DATE,
            self::DISPLAY_CSV,
            self::DISPLAY_TABLE
        ));

        $this->println();
        $this->println("login:\t\tYour login email or surname");
        $this->println("passwd:\t\tThe base64 encoded password");

        $this->println(sprintf(
            "columns:\tThe number of columns to show. Defaults to %s. Must be higher than 0",
            self::DEFAULT_COLUMNS
        ));
        $this->println("date:\t\tThe date to check");
        $this->println("course:\t\tThe golf course identifier for the first selected golfcourse");
        $this->println("\t\tThe default is de default first loaded golfcourse as can be seen in the browser");
        $this->println("\t\tThe value if given must match the value in the select box as can be seen in the browser");
        $this->println("cache:\t\tCache file location where to load and store session data");
        $this->println("\t\tThe file need not exist, but if it does it needs to contain valid sessiondata");
        $this->println("\t\tSession data will be written to it");
        $this->println();
    }

    public function run(): void
    {
        try {
            $this->displayAvailableTimes($this->config);
        } catch (Throwable $exception) {
            $this->showHelp($exception);
            exit($exception->getCode() ?: self::ERROR_RESULT);
        }
    }
})->run();
