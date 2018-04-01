<?php

namespace Crusse;

/**
 * A shared memory cache for storing data that is persisted across multiple PHP
 * script runs.
 * 
 * Features:
 * 
 * - Stores the hash table and items' values in Unix shared memory
 * - FIFO queue: tries to evict the oldest items when the cache is full
 *
 * The same memory block is shared by all instances of ShmCache. This means the
 * maximum amount of memory used by ShmCache is always DEFAULT_CACHE_SIZE, or
 * $desiredSize, if defined.
 *
 * You can use the Unix programs `ipcs` and `ipcrm` to list and remove the
 * memory block created by this class, if something goes wrong.
 *
 * It is important that the first instantiation and any further uses of this
 * class are with the same Unix user (e.g. 'www-data'), because the shared
 * memory block cannot be deleted (e.g. in destroy()) by another user, at least
 * on Linux. If you have problems deleting the memory block created by this
 * class via $cache->destroy(), using `ipcrm` as root is your best bet.
 */
class ShmCache {

  const FLAG_SERIALIZED = 0b00000001;

  private $memAllocLock;
  private $statsLock;
  private $hashBucketLocks = [];

  private $getHits = 0;
  private $getMisses = 0;

  private $memory;

  /**
   * @param $desiredSize The size of the shared memory block, which will contain all ShmCache data. If a block already exists and its size is larger, the block's size will not be reduced. If its size is smaller, it will be enlarged.
   *
   * @throws \Exception
   */
  function __construct( $desiredSize = 0 ) {

    if ( !is_int( $desiredSize ) ) {
      throw new \InvalidArgumentException( '$desiredSize must be an integer' );
    }
    else if ( $desiredSize && $desiredSize < 1024 * 1024 * 16 ) {
      throw new \InvalidArgumentException( '$desiredSize must be at least 16 MiB, but you defined it as '.
        round( $desiredSize / 1024 / 1024, 5 ) .' MiB' );
    }

    $this->memAllocLock = new ShmCache\Lock( 'memalloc' );
    $this->statsLock = new ShmCache\Lock( 'stats' );

    if ( !$this->memAllocLock->getWriteLock() )
      throw new \Exception( 'Could not get a lock' );

    $this->memory = new ShmCache\MemoryBlock( $desiredSize, self::MAX_KEY_LENGTH );

    if ( !$this->memAllocLock->releaseLock() )
      throw new \Exception( 'Could not release a lock' );
  }

  function __destruct() {

    if ( $this->memory ) {
      $this->flushBufferedStatsToShm();
      unset( $this->memory );
    }
  }

  function set( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $ret = $this->_set( $key, $value, $retIsSerialized );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function get( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getReadLock() )
      return false;

    $ret = $this->_get( $key, $retIsSerialized, $retIsCacheHit );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    if ( $ret && $retIsSerialized )
      $ret = unserialize( $ret );

    if ( $retIsCacheHit )
      ++$this->getHits;
    else
      ++$this->getMisses;

    return $ret;
  }

  function exists( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getReadLock() )
      return false;

    $ret = (bool) $this->getChunkByKey( $key );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function add( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $ret = $this->_set( $key, $value, $retIsSerialized, true );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function replace( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $ret = $this->_set( $key, $value, $retIsSerialized, false, true );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function increment( $key, $offset = 1, $initialValue = 0 ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $value = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    if ( $retIsSerialized )
      $value = unserialize( $value );

    if ( $value === false ) {
      $value = $initialValue;
    }
    else if ( !is_numeric( $value ) ) {
      trigger_error( 'Item "'. $key .'" value is not numeric' );
      $lock->releaseLock();
      return false;
    }

    $value = max( $value + $offset, 0 );
    $valueSerialized = $this->maybeSerialize( $value, $retIsSerialized );
    $success = $this->_set( $key, $valueSerialized, $retIsSerialized );

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    if ( $success )
      return $value;

    return false;
  }

  function decrement( $key, $offset = 1, $initialValue = 0 ) {

    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    return $this->increment( $key, -$offset, $initialValue );
  }

  function delete( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $ret = false;
    $chunk = $this->getChunkByKey( $key );

    if ( $chunk ) {
      // Already free
      if ( !$chunk->valsize )
        $ret = true;
      else
        $ret = $this->removeChunk( $chunk );
    }

    $lock->releaseLock();
    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function flush() {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->memAllocLock->getWriteLock() )
      return false;

    try {
      $this->clearMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  /**
   * Deletes the shared memory block created by this class. This will only
   * work if the block was created by the same Unix user or group that is
   * currently running this PHP script.
   */
  function destroy() {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->memAllocLock->getWriteLock() )
      return false;

    try {
      $this->flushBufferedStatsToShm();
      $this->destroyMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function getStats() {

    if ( !$this->memAllocLock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    $ret = (object) [
      'items' => 0,
      'maxItems' => $this->MAX_ITEMS,
      'availableHashTableSlots' => $this->KEYS_SLOTS,
      'usedHashTableSlots' => 0,
      'hashTableLoadFactor' => 0,
      'hashTableMemorySize' => $this->KEYS_SIZE,
      'availableValueMemSize' => $this->VALUES_SIZE,
      'usedValueMemSize' => 0,
      'avgItemValueSize' => 0,
      'oldestZoneIndex' => $this->getOldestZoneIndex(),
      'getHitCount' => $this->getGetHits(),
      'getMissCount' => $this->getGetMisses(),
      'itemMetadataSize' => $this->CHUNK_META_SIZE,
      'minItemValueSize' => self::MIN_VALUE_ALLOC_SIZE,
      'maxItemValueSize' => self::MAX_CHUNK_SIZE,
    ];

    for ( $i = $this->KEYS_START; $i < $this->KEYS_START + $this->KEYS_SIZE; $i += $this->LONG_SIZE ) {
      // TODO: acquire item lock?
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        ++$ret->usedHashTableSlots;
    }

    $ret->hashTableLoadFactor = $ret->usedHashTableSlots / $ret->availableHashTableSlots;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      // TODO: acquire item lock?
      $item = $this->getChunkByOffset( $i );

      if ( $item[ 'valsize' ] ) {
        ++$ret->items;
        $ret->usedValueMemSize += $item[ 'valsize' ];
      }

      $i += $this->CHUNK_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->memAllocLock->releaseLock() )
      throw new \Exception( 'Could not release a lock' );

    $ret->avgItemValueSize = ( $ret->items )
      ? $ret->usedValueMemSize / $ret->items
      : 0;

    return $ret;
  }

  private function getHashBucketLock( $key ) {

    $index = $this->memory->getBucketIndex( $key );

    if ( !isset( $this->bucketLocks[ $index ] ) )
      $this->bucketLocks[ $index ] = new ShmCache\Lock( 'bucket'. $index );

    return $this->bucketLocks[ $index ];
  }

  private function maybeSerialize( $value, &$retIsSerialized ) {

    $retIsSerialized = false;

    if ( !is_string( $value ) ) {
      $value = serialize( $value );
      $retIsSerialized = true;
    }

    return $value;
  }

  private function _get( $key, &$retIsSerialized, &$retIsCacheHit ) {

    $ret = false;
    $retIsCacheHit = false;
    $retIsSerialized = false;
    $chunk = $this->getChunkByKey( $key );

    if ( $chunk ) {

      $data = $this->getChunkValue( $chunk );

      if ( $data === false ) {
        trigger_error( 'Could not read value for item "'. rawurlencode( $key ) .'"' );
      }
      else {
        $retIsSerialized = $chunk->flags & self::FLAG_SERIALIZED;
        $retIsCacheHit = true;
        $ret = $data;
      }
    }

    return $ret;
  }

  private function _set( $key, $value, $valueIsSerialized, $mustNotExist = false, $mustExist = false ) {

    $valueSize = strlen( $value );
    $existingChunk = $this->getChunkByKey( $key );

    if ( $existingChunk ) {

      if ( $mustNotExist )
        return false;

      if ( $this->replaceChunkValue( $existingChunk, $value, $valueSize, $valueIsSerialized ) ) {
        return true;
      }
      else {
        // The new value is probably too large to fit into the existing chunk, and
        // would overwrite 1 or more chunks to the right of it. We'll instead
        // remove the existing chunk, and handle this as a new value.
        //
        // Note: whenever we cannot store the value to the cache, we remove any
        // existing item with the same key. This emulates Memcached:
        // https://github.com/memcached/memcached/wiki/Performance#how-it-handles-set-failures
        if ( !$this->removeChunk( $existingChunk ) )
          return false;
      }
    }
    else {
      if ( $mustExist )
        return false;
    }

    if ( !$this->memory->addChunk( $key, $value, $valueSize, $valueIsSerialized ) )
      return false;

    return true;
  }

  private function sanitizeKey( $key ) {
    return substr( $key, 0, self::MAX_KEY_LENGTH );
  }

  private function flushBufferedStatsToShm() {

    // Flush all of our get() hit and miss counts to the shared memory
    try {
      if ( $this->statsLock->getWriteLock() ) {

        if ( $this->getHits ) {
          $this->setGetHits( $this->getGetHits() + $this->getHits );
          $this->getHits = 0;
        }

        if ( $this->getMisses ) {
          $this->setGetMisses( $this->getGetMisses() + $this->getMisses );
          $this->getMisses = 0;
        }

        $this->statsLock->releaseLock();
      }
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
    }
  }
}


