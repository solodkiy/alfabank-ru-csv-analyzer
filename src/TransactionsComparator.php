<?php
declare(strict_types = 1);


namespace Solodkiy\AlfaBankRu\CsvAnalyzer;

use Brick\DateTime\LocalDate;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Solodkiy\AlfaBankRu\CsvAnalyzer\Model\Transaction;
use Solodkiy\AlfaBankRu\CsvAnalyzer\Model\TransactionsCollection;
use Solodkiy\AlfaBankRu\CsvAnalyzer\Model\TransactionsDiff;
use Solodkiy\AlfaBankRu\CsvAnalyzer\Model\TransactionsMatchMode;
use Webmozart\Assert\Assert;

final class TransactionsComparator
{
    use LoggerAwareTrait;

    /**
     * @var DescriptionParser
     */
    private $descriptionParser;

    /**
     * TransactionCollectionsDiffer constructor.
     */
    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->descriptionParser = new DescriptionParser();
    }

    public function diff(TransactionsCollection $currentCollection, TransactionsCollection $newCollection) : TransactionsDiff
    {
        return $this->internalDiff($currentCollection, $newCollection, false);
    }

    private function internalDiff(TransactionsCollection $currentCollection, TransactionsCollection $newCollection, bool $softMode) : TransactionsDiff
    {
        $originCurrentCollection = $currentCollection;
        $originNewCollection = $newCollection;

        if ($newCollection->isEmpty()) {
            // Возвращаем emptyDiff. В реальности это может быть ошибкой, но пока забиваем на это
            return new TransactionsDiff();
        }

        $diff = new TransactionsDiff();
        $currentCollection = $this->trimBeforeDay($currentCollection, $newCollection->getFirstDay());

        // Удаляем из все транзакции присутствующие в обоих файлах
        foreach ($newCollection as $newTransaction) {
            /** @var Transaction $newTransaction */
            $currentTransaction = $currentCollection->findByData($newTransaction);
            if ($currentTransaction) {
                $currentCollection = $currentCollection->without($currentTransaction->getId());
                $newCollection = $newCollection->without($newTransaction->getId());
                $diff->addSame($currentTransaction->getId(), $newTransaction);
            }
        }

        $this->logger->debug('Transactions after first clean up. Current collection: ' . count($currentCollection).', New collection: ' . count($newCollection));

        foreach ($newCollection->filterHold() as $newHoldTransaction) {
            $diff->addNew($newHoldTransaction);
            $newCollection = $newCollection->without($newHoldTransaction->getId());
        }
        $this->logger->debug('Filter ' . $diff->countNewHold() . ' new hold ');


        // Process disappeared transactions
        /*
        foreach ($currentCollection->filterCommitted() as $transaction) {
            /** @var $transaction CardTransaction *
            if ($transaction->getType()->equals(TransactionType::IN())) {
                $currentCollection = $currentCollection->without($transaction->getId());
                $this->logger->info('skip disappeared in transaction (' . $transaction->getId() . ')');
            }
        }
        */
        $leftCommitted = $currentCollection->filterCommitted();
        if (count($leftCommitted) > 0) {
            foreach ($leftCommitted as $transaction) {
                /** @var $transaction Transaction */
                $reference = $transaction->getReference();
                if ($reference[0] == 'B') {
                    $this->logger->warning('Skip disappeared B-type transaction: ' . $reference);
                    $leftCommitted = $leftCommitted->without($transaction->getId());
                }
            }
        }
        if (count($leftCommitted) > 0) {
            throw new \RuntimeException('Found disappeared committed transactions!');
        }

        /** @var Transaction $newCommittedTransaction */
        foreach ($newCollection->filterCommitted() as $newCommittedTransaction) {
            $mode = $softMode ? TransactionsMatchMode::SOFT() : TransactionsMatchMode::NORMAL();

            $holdOne = $this->matchHold($currentCollection, $newCommittedTransaction, $mode);
            if ($holdOne) {
                $currentCollection = $currentCollection->without($holdOne->getId());
                $diff->addUpdated($holdOne->getId(), $newCommittedTransaction);
            } else {
                // new Committed
                $diff->addNew($newCommittedTransaction);
            }
            $newCollection = $newCollection->without($newCommittedTransaction->getId());
        }

        if (count($currentCollection)) {
            if ($diff->countNewCommitted() > 0) {
                if (!$softMode) {
                    $this->logger->warning('Found '. count($currentCollection). ' disappeared transaction. Try SoftMode');
                    return $this->internalDiff($originCurrentCollection, $originNewCollection, true);
                } else {
                    $newOutCommittedList = array_filter($diff->getNewCommitted(), function (Transaction $transaction) {
                        // filter only out CRD transactions
                        return $transaction->getType()->isOut() && substr((string)$transaction->getReference(), 0, 4) === 'CRD_';
                    });
                    if (count($newOutCommittedList) === 1 && count($currentCollection) === 1) {
                        $this->logger->warning('Found disappeared transaction. Try ExtraSoftMode match');
                        $holdOne = Utils::first($currentCollection);
                        $newCommittedTransaction = Utils::first($newOutCommittedList);
                        $isSame = $this->isTransactionsSame($newCommittedTransaction, $holdOne, TransactionsMatchMode::EXTRA_SOFT());
                        if ($isSame) {
                            $diff->addUpdated($holdOne->getId(), $newCommittedTransaction);
                            $diff->deleteNew($newCommittedTransaction);
                        } else {
                            throw new \RuntimeException('Found disappeared transaction. In ExtraSoftMode!');
                        }
                    } else {
                        foreach ($currentCollection as $transaction) {
                            $this->logger->debug('Disappear: ' . $transaction->getDescription());
                        }
                        foreach ($newOutCommittedList as $transaction) {
                            $this->logger->debug('New out: ' . $transaction->getReference());
                        }
                        throw new \RuntimeException('Found '.count($currentCollection). ' disappeared transaction. In SoftMode!');
                    }
                }
            } else {
                // Считаем эти транзакции за отменённые
                $descriptions = Utils::map($currentCollection, function (Transaction $tr) {
                    return $tr->getDescription();
                });
                $this->logger->warning('Delete '.count($currentCollection). ' hold transactions ('.implode(',', $descriptions) . ')');
                foreach ($currentCollection as $disappearedHoldTransaction) {
                    $diff->addDeleted($disappearedHoldTransaction->getId());
                }
            }
        }

        $diff->freeze();
        return $diff;
    }

    private function trimBeforeDay(TransactionsCollection $collection, LocalDate $day): TransactionsCollection
    {
        return $collection->filter(function (Transaction $transaction) use ($day) {
            return $transaction->getDate()->isAfterOrEqualTo($day);
        });
    }

    private function matchHold(TransactionsCollection $storedHoldTransactions, Transaction $committedTransaction, TransactionsMatchMode $mode = null): ?Transaction
    {
        $mode = $mode ?? TransactionsMatchMode::NORMAL();
        $equalAmount = Utils::filter($storedHoldTransactions, function (Transaction $holdTransaction) use ($committedTransaction, $mode) {
            return $this->isTransactionsSame($committedTransaction, $holdTransaction, $mode);
        });
        if (count($equalAmount) === 1) {
            return Utils::first($equalAmount);
        } elseif (count($equalAmount) > 1) {
            // If all equal transactions are the same then pick first one
            if ($this->isAllHoldTransactionsSame($equalAmount)) {
                return Utils::first($equalAmount);
            }

            if ($mode->equals(TransactionsMatchMode::NORMAL())) {
                // try hard mode
                $this->logger->warning('Found more than one. Try hard mode');
                return $this->matchHold($storedHoldTransactions, $committedTransaction, TransactionsMatchMode::HARD());
            }

            throw new \RuntimeException('Matched more than one hold transactions: ' . count($equalAmount));
        }
        return null;
    }

    /**
     * @param Transaction[] $list
     * @return bool
     */
    private function isAllHoldTransactionsSame(array $list) : bool
    {
        Assert::greaterThanEq($list, 1);
        $list = array_values($list);
        $first = $list[0];
        foreach (array_slice($list, 1) as $item) {
            if ($first->getAccount() != $item->getAccount()) {
                return false;
            }
            if (!$first->getDate()->isEqualTo($item->getDate())) {
                return false;
            }

            if (!$first->getAmount()->isEqualTo($item->getAmount())) {
                return false;
            }

            if ($first->getDescription() != $item->getDescription()) {
                return false;
            }
        }

        return true;
    }

    private function isTransactionsSame(Transaction $committed, Transaction $hold, TransactionsMatchMode $mode)
    {
        $typeEquals = $hold->getType()->equals($committed->getType());
        if (!$typeEquals) {
            return false;
        }

        $committedInfo = $this->descriptionParser->extractCommitted($committed->getDescription());
        $holdInfo = $this->descriptionParser->extractHold($hold->getDescription());
        $cardEquals = $committedInfo['card'] == $holdInfo['card'];

        if (!$cardEquals) {
            return false;
        }


        if ($mode->isExtraSoft()) {
            return true;
        }

        if ($committedInfo && $holdInfo && !$mode->isExtraSoft()) {
            if ($committedInfo['code'] && $holdInfo['code'] && !$mode->isSoft()) {
                if ($committedInfo['code'] != $holdInfo['code']) {
                    return false;
                }
            }
            if ($mode->isHard()) {
                if (!$hold->getDate()->isEqualTo($committedInfo['hold_date'])) {
                    return false;
                }
            }

            return (Utils::isMoneyEquals($committedInfo['amount'], $holdInfo['amount']));
        }

        $amountEquals = Utils::isMoneyEquals($hold->getAmount(), $committed->getAmount());
        if ($amountEquals) {
            return true;
        } else {
            if ($mode->isSoft()) {
                if (is_null($committedInfo)) {
                    return false;
                }
                $sourceCurrency = $committedInfo['amount']->getCurrency();
                $realCurrency = $committed->getAmount()->getCurrency();
                if (!$realCurrency->is($sourceCurrency)) {
                    return Utils::isMoneyNearlyEquals($committed->getAmount(), $hold->getAmount());
                }
            }
            return false;
        }
    }
}
