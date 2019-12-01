<?php

namespace App\Console\Commands;

use Faker\Factory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Class CacheTest
 *
 * @package App\Console\Commands
 */
class CacheTest extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:test {driver}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run speed tests for the given cache driver. Timings given in microseconds.';

    /**
     * @var string
     */
    protected $driver;

    /**
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * @var \Faker\Generator
     */
    protected $faker;

    /**
     * @var int
     */
    protected $runs = 1000;

    /**
     * @throws \Exception
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function handle()
    {
        $this->driver = $this->argument('driver');

        if(empty($this->driver) || !in_array($this->driver, array_keys(config('cache.stores'))))
        {
            throw new \Exception("Missing / unsupported cache driver '".$this->driver."'.");
        }

        $this->cache = Cache::store($this->driver);

        $this->faker = Factory::create($locale ?? config('app.faker_locale', Factory::DEFAULT_LOCALE));

        $timings = $this->performTests();

        $this->displayResults($timings);
    }

    /**
     * @param \Illuminate\Support\Collection $timings
     */
    protected function displayResults(Collection $timings)
    {
        $output = $timings->map(function(Collection $results, string $test) {
            return [
                'test'  => ucfirst($test),
                'write' => $results['write']->average(),
                'read'  => $results['read']->average(),
            ];
        });

        $this->info('Cache Driver: '.$this->driver);

        $this->table(['Test', 'Write', 'Read',], $output);
    }

    /**
     * @return \Illuminate\Support\Collection
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function performTests() : Collection
    {
        $timings = [];

        $timings['integer'] = $this->performTest(
            $this->faker->numberBetween(0, 9999)
        );

        $timings['stats'] = $this->performTest(
            json_encode(function() {
                $data = [];
                for($i=1; $i<=100; $i++) {
                    $data[] = $this->faker->numberBetween(0, 9999);
                }
                return $data;
            })
        );

        $timings['paragraph'] = $this->performTest(
            $this->faker->sentence(150)
        );

        $timings['article'] = $this->performTest(
            $this->faker->words(2000, true)
        );

        $timings['webpage'] = $this->performTest(
            Storage::disk('local')->get('bbc-news-article.html')
        );

        return new Collection($timings);
    }

    /**
     * @param string $data
     *
     * @return \Illuminate\Support\Collection
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function performTest(string $data) : Collection
    {
        return new Collection([
            'write' => $this->performTestWrite($data),
            'read'  => $this->performTestRead($data),
        ]);
    }

    /**
     * @param string $data
     *
     * @return \Illuminate\Support\Collection
     */
    protected function performTestWrite(string $data)
    {
        $this->cache->flush();

        $timings = [];

        for($i=1; $i<=$this->runs; $i++)
        {
            $cache_key = $this->driver.'-write-'.$i;

            $time_start = (int) (microtime(true) * 1000000);

            $this->cache->put($cache_key, $data, 600);

            $time_end = (int) (microtime(true) * 1000000);

            $timings[] = $time_end - $time_start;
        }

        return new Collection($timings);
    }

    /**
     * @param string $data
     *
     * @return \Illuminate\Support\Collection
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function performTestRead(string $data)
    {
        $this->cache->flush();

        $cache_key = $this->driver.'-read';

        $this->cache->put($cache_key, $data, 600);

        $timings = [];

        for($i=1; $i<=$this->runs; $i++)
        {
            $time_start = (int) (microtime(true) * 1000000);

            $retrieved = $this->cache->get($cache_key);

            $time_end = (int) (microtime(true) * 1000000);

            $timings[] = $time_end - $time_start;
        }

        return new Collection($timings);
    }

}
