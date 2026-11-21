<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * The API specs in docs/api, for tests that check what the site really
 * sends against what the specs say it sends
 */
class ApiSpec
{
    /** Where the specs are registered, so one can $ref the other by file name */
    const BASE_URI = 'https://mapchat.test/docs/api/';

    /** The spec files, in docs/api */
    const FILES = ['openapi.yaml', 'asyncapi.yaml'];

    private static ?Validator $validator = null;

    /**
     * A spec file as an array, e.g. to list its paths
     *
     * @return array<string, mixed>
     */
    public static function read(string $file): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2) . "/docs/api/$file");
    }

    /**
     * What's wrong with a value against a schema in a spec, or nothing when
     * it matches
     *
     * @param mixed $value Decoded with json_decode($json) as it is, objects as stdClass
     * @param string $file openapi.yaml or asyncapi.yaml
     * @param string $pointer The schema's JSON pointer in the file, e.g. /components/schemas/Person
     *
     * @return string[] Each error, with where in the value it is
     */
    public static function errorsIn(mixed $value, string $file, string $pointer): array
    {
        $result = static::validator()->validate($value, static::BASE_URI . $file . '#' . $pointer);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), multiple: true) as $path => $messages) {
            $errors[] = "$path: " . implode('; ', $messages);
        }

        return $errors;
    }

    /**
     * A JSON Schema 2020-12 validator that knows both specs, made once
     */
    private static function validator(): Validator
    {
        if (static::$validator !== null) {
            return static::$validator;
        }

        $validator = new Validator();
        $validator->parser()->setDefaultDraftVersion('2020-12');
        foreach (static::FILES as $file) {
            // Opis reads a schema as JSON; a spec's other keys are ones JSON Schema ignores
            $validator->resolver()->registerRaw(json_encode(static::read($file)), static::BASE_URI . $file);
        }

        return static::$validator = $validator;
    }
}
