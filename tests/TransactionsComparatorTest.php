<?php

namespace Solodkiy\AlfaBankRu\CsvAnalyzer;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class TransactionsComparatorTest extends TestCase
{
    /**
     * @param string $currentPath
     * @param string $newPath
     * @param array $expectedStat
     * @dataProvider createDiffProvider
     */
    public function testCreateDiff(string $currentPath, string $newPath, array $expectedStat)
    {
        $loader = new CsvLoader();
        $currentCollection = $loader->loadFromFile($currentPath);
        $newCollection = $loader->loadFromFile($newPath);

        $printLogger = new class extends AbstractLogger {
            public function log($level, $message, array $context = array())
            {
                echo '[' . $level . '] ' . $message . "\n";
            }
        };

        $differ = new TransactionsComparator();
        //$differ->setLogger($printLogger);
        $diff = $differ->diff($currentCollection, $newCollection);

        $this->assertEquals($expectedStat, $diff->stat());
    }

    public function createDiffProvider()
    {
        return [
            [
                __DIR__ . '/data/movementList_2018-02-28_18:15:23.csv',
                __DIR__ . '/data/movementList_2018-02-28_18:15:23.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/movementList_2018-02-28_18:15:23.csv',
                __DIR__ . '/data/movementList_2018-03-07_19:45:18.csv',
                [
                    'new_hold' => 5,
                    'new_committed' => 19,
                    'updated' => 6,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/movementList_2018-02-28_18:15:23.csv',
                __DIR__ . '/data/movementList_2018-02-28_20:00:17.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 1,
                    'updated' => 0,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/full/first.csv',
                __DIR__ . '/data/full/second.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 0,
                    'updated' => 1,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/empty_csv/movementList_2018-10-20_22:30:36.csv',
                __DIR__ . '/data/empty_csv/movementList_2018-10-21_21:45:35.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/two_equal_transactions/1.csv',
                __DIR__ . '/data/two_equal_transactions/2.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 0,
                    'updated' => 3,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/committed_in/first.csv',
                __DIR__ . '/data/committed_in/second.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 1,
                    'updated' => 1,
                    'deleted' => 0,
                ]
            ],
            [
                __DIR__ . '/data/reference_collision/first.csv',
                __DIR__ . '/data/reference_collision/second.csv',
                [
                    'new_hold' => 0,
                    'new_committed' => 1,
                    'updated' => 1,
                    'deleted' => 0,
                ]
            ],
            /*
            [
                __DIR__ . '/data/trans/first.csv',
                __DIR__ . '/data/trans/second.csv',
                [
                    'new_hold' => 10,
                    'new_committed' => 7,
                    'updated' => 25,
                    'deleted' => 0,
                ]
            ],
            */
            [
                __DIR__ . '/data/case9/first.csv',
                __DIR__ . '/data/case9/second.csv',
                [
                    'new_hold' => 3,
                    'new_committed' => 0,
                    'updated' => 4,
                    'deleted' => 0,
                ]
            ],
        ];
    }
}
