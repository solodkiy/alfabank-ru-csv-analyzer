Utils for analyze csv files from click.alfabank.ru
==================================

**TransactionsComparator** Поволяет анализировать csv скаченные с click.alfabank.ru, а также сравнивать их между собой
```php
$loader = new CsvLoader();
$currentCollection = YourStorage::loadCurrentTransactionsFromDb();
$newCollection = $loader->loadFromFile(__DIR__ .'/../tests/data/movementList_2018-03-07_19:45:18.csv');

$differ = new TransactionsComparator();
$diff = $differ->diff($currentCollection, $newCollection);

YourStorage::insertTransactionsToDb($diff->getNewCommitted());
YourStorage::insertTransactionsToDb($diff->getNewHold());
YourStorage::updateTransactionsInDb($diff->getUpdated());
YourStorage::deleteTransactionsFromDb($diff->getDeletedIds());
```