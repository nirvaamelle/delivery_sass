<?php

namespace App\Domain\Documents;

use App\Models\DocumentLink;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The handoff spine — PLAN.md §1.
 *
 * "Every document carries the project code, the cost code, and the reference
 * number of the document before it." The first two are columns on each
 * document; the third is an edge, and this service owns it.
 *
 * The spine is a directed acyclic graph, not a list. A PO has two predecessors
 * (an approved PR and a tabulated bid), and one PR can produce several POs. The
 * guards below are what keep it acyclic, because the question the spine exists
 * to answer — trace this document back to its origin — has no answer at all in
 * a graph with a cycle.
 */
class DocumentLinker
{
    /**
     * Record that $successor follows $predecessor.
     *
     * @throws DomainException when the edge is a self-link or would close a cycle
     */
    public function link(Model $predecessor, Model $successor): DocumentLink
    {
        if ($this->isSame($predecessor, $successor)) {
            throw new DomainException(
                'A document cannot be its own predecessor.'
            );
        }

        // Adding predecessor → successor closes a cycle exactly when the
        // predecessor is already reachable by following edges forward from the
        // successor.
        if ($this->reaches($successor, $predecessor)) {
            throw new DomainException(
                'Linking these documents would close a cycle in the handoff spine.'
            );
        }

        return DocumentLink::query()->create([
            'predecessor_type' => $predecessor->getMorphClass(),
            'predecessor_id' => $predecessor->getKey(),
            'successor_type' => $successor->getMorphClass(),
            'successor_id' => $successor->getKey(),
        ]);
    }

    /**
     * The documents this one produced.
     *
     * @return Collection<int, Model>
     */
    public function successorsOf(Model $document): Collection
    {
        return DocumentLink::query()
            ->where('predecessor_type', $document->getMorphClass())
            ->where('predecessor_id', $document->getKey())
            ->with('successor')
            ->get()
            ->map(fn (DocumentLink $link): ?Model => $link->successor)
            ->filter()
            ->values();
    }

    /**
     * The documents this one came from.
     *
     * @return Collection<int, Model>
     */
    public function predecessorsOf(Model $document): Collection
    {
        return DocumentLink::query()
            ->where('successor_type', $document->getMorphClass())
            ->where('successor_id', $document->getKey())
            ->with('predecessor')
            ->get()
            ->map(fn (DocumentLink $link): ?Model => $link->predecessor)
            ->filter()
            ->values();
    }

    /**
     * Every document upstream of this one, however many hops back.
     *
     * @return Collection<int, Model>
     */
    public function ancestorsOf(Model $document): Collection
    {
        /** @var Collection<int, Model> $found */
        $found = collect();
        $seen = [$this->key($document) => true];
        $queue = [$document];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->predecessorsOf($current) as $predecessor) {
                $key = $this->key($predecessor);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $found->push($predecessor);
                $queue[] = $predecessor;
            }
        }

        return $found;
    }

    /**
     * Can $target be reached from $origin by following edges forward?
     */
    private function reaches(Model $origin, Model $target): bool
    {
        $targetKey = $this->key($target);
        $seen = [$this->key($origin) => true];
        $queue = [$origin];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->successorsOf($current) as $successor) {
                $key = $this->key($successor);

                if ($key === $targetKey) {
                    return true;
                }

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = $successor;
            }
        }

        return false;
    }

    private function isSame(Model $first, Model $second): bool
    {
        return $this->key($first) === $this->key($second);
    }

    private function key(Model $document): string
    {
        return $document->getMorphClass().':'.$document->getKey();
    }
}
