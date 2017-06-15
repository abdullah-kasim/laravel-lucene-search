<?php namespace tests\functional;

use tests\TestCase;
use Config;

/**
 * Class BaseTestCase
 * @package tests\functional
 */
abstract class BaseTestCase extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->configure();

        $artisan = $this->app->make('artisan');

        // Call migrations specific to our tests, e.g. to seed the db.
        $artisan->call('migrate', ['--database' => 'testbench', '--path' => '../tests/migrations']);

        // Call rebuild search index.
        $artisan->call('search:rebuild');
    }

    protected function configure()
    {
        Config::set('laravel-lucene-search::index.path', storage_path() . '/lucene-search/index_' . uniqid());
        Config::set(
            'laravel-lucene-search::index.models',
            [
                'tests\models\Product' => [
                    'fields' => [
                        'name',
                        'description',
                    ],
                    'optional_attributes' => [
                        'accessor' => 'custom_optional_attributes'
                    ],
                ],
                'tests\models\Tool' => [
                    'fields' => [
                        'name',
                        'description',
                    ]
                ]
            ]
        );
    }
}
