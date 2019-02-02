The locking implementation
--------------------------

## TODO

- Fix locking bugs in the PHP code
- Maybe implement a lock correctness validator like in https://www.kernel.org/doc/Documentation/locking/lockdep-design.txt


## Memory block structure

#### Metadata area:

    [oldestzoneindex]

The 'oldestzoneindex' points to the oldest zone in the zones area.
When the cache is full, the oldest zone is evicted.

#### Stats area:

    [gethits][getmisses]

#### Hash table bucket area:

    [itemmetaoffset,itemmetaoffset,...]

Our hash table uses "separate chaining" (look it up). The 'itemmetaoffset'
points to a chunk in a zone's 'chunksarea'.

#### Zones area:

    [[usedspace,chunksarea],[usedspace,chunksarea],...]

The zones area is a ring buffer. The 'oldestzoneindex' points to
the oldest zone. Each zone is a stack of chunks.

A zone's 'usedspace' can be used to calculate the first free chunk in
that zone. All chunks up to that point is memory in use; all chunks
after that point is free space. 'usedspace' is therefore essentially
a stack pointer.

Each zone is roughly in the order in which the zones were created, so
that we can easily find the oldest zones for eviction, to make space for
new cache items.

Each chunk contains a single cache item. A 'chunksarea' looks like this:

#### Chunks area:

    [[key,hashnext,valallocsize,valsize,flags,value],...]

'key' is the hash table key as a string.

'hashnext' is the offset (in the zone area) of the next chunk in
the current hash table bucket. If 0, it's the last entry in the bucket.
This is used to traverse the entries in a hash table bucket, which is
a linked list.

If 'valsize' is 0, that value slot is free. This doesn't mean that all
the next chunks in this zone are free as well -- only the zone's usedspace
(i.e. its stack pointer) tells where the zone's free area starts.

'valallocsize' is how big the allocated size is. This is usually the
same as 'valsize', but can be larger if the value was replaced with
a smaller value later, or if `valsize < MIN_VALUE_ALLOC_SIZE`.

To traverse a single zone's chunks from left to right, keep incrementing
your offset by chunkSize.

`chunkSize = CHUNK_META_SIZE + valallocsize`


## The locks

- __Stats lock:__
  locks all access to the stats memory region
- __Bucket locks:__
  locks a hash table bucket, and the hashNext property of all entries in that bucket
- __Zone locks:__
  locks a single zone and all chunks in it
- __Zones area ring buffer lock:__
  locks the 'oldestzoneindex' ring buffer pointer


## Locking rules

- __RULE 1:__
  to modify anything in a chunk, you must hold the chunk's associated hash
  table bucket's lock

- __RULE 2:__ to modify _anything_ in a zone, you must hold that zone's lock

- __RULE 3:__ the lock order between a bucket and a zone lock must always be
  bucket -> zone, not zone -> bucket. For ringBufferPtr it's always
  ringBufferPtr -> zone. This makes the full lock order be:

  Bucket lock -> ringBufferPtr lock -> zone lock

- __RULE 4:__ multiple zones can't be locked at the same time by a single process

- __RULE 5:__ multiple buckets can be locked simultaneously by a single process,
  but _only_ by using a try-lock when trying to lock the 2nd..Nth bucket (see
  **RULE 6**). If the try-lock fails, the process must drop its zone lock and
  ringBufferPtr lock before trying again.

- __RULE 6:__ you can violate **RULE 3's** bucket -> zone order and acquire a lock
  in zone -> bucket order, but only if you use a try-lock when locking the bucket
  after having locked the zone with a normal lock (not a try-lock). For example:

        P1: LOCK bucket A
                               P2: LOCK bucket B
        P1: LOCK RBP
        P1: LOCK zone Y
                               P2: LOCK RBP
        P1: TRYLOCK bucket B
            (fail)
        P1: UNLOCK zone Y
        P1: UNLOCK RBP
                               P2: LOCK zone Y
                               P2: TRYLOCK bucket B
                                   (success: P2 already has it)
                               P2: Do some operation on zone Y
                               P2: UNLOCK zone Y
                               P2: UNLOCK RBP
        P1: LOCK RBP
        P1: LOCK zone Y
        P1: TRYLOCK bucket A
            (success: P1 already has it)
        P1: Do some operation on zone Y
        P1: UNLOCK zone Y

