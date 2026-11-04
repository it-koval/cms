<?php

declare(strict_types=1);

namespace @@namespace@@;

@@uses@@

@@attributes@@final class @@class@@ extends CollectionResource
{
    public function key(): string
    {
        return '@@key@@';
    }

    public function title(): string
    {
        return @@titleQ@@;
    }
@@methods@@
    public function fields(): iterable
    {
@@fields@@
    }

    // Optional: filters(), metrics(), actions(), views(), defaultSort(), onLoad() and the hooks (beforeCreating, afterUpdated …). See the bundle README.
}
