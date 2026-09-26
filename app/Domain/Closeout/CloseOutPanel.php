<?php

namespace App\Domain\Closeout;

/**
 * Slide 9's three closing panels.
 *
 * Typed because the close-out report is read panel by panel — the documents
 * belong to the PMO, the financial close to Finance, the people and assets to
 * HR and Operations — and a flat list of eleven lines is one nobody owns.
 */
enum CloseOutPanel: string
{
    case Documents = 'documents';
    case Financial = 'financial';
    case PeopleAndAssets = 'people_and_assets';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
