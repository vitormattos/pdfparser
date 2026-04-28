<?php

/**
 * @file This file is part of the PdfParser library.
 *
 * @author  Konrad Abicht <k.abicht@gmail.com>
 *
 * @date    2020-06-02
 *
 * @author  Sébastien MALOT <sebastien@malot.fr>
 *
 * @date    2017-01-03
 *
 * @license LGPLv3
 *
 * @url     <https://github.com/smalot/pdfparser>
 *
 *  PdfParser is a pdf library written in PHP, extraction oriented.
 *  Copyright (C) 2017 - Sébastien MALOT <sebastien@malot.fr>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with this program.
 *  If not, see <http://www.pdfparser.org/sites/default/LICENSE.txt>.
 */

namespace PHPUnitTests;

use PHPUnit\Framework\TestCase as PHPTestCase;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element;
use Smalot\PdfParser\Parser;

abstract class TestCase extends PHPTestCase
{
    /**
     * Contains an instance of the class to test.
     */
    protected $fixture;

    protected $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = __DIR__.'/../..';
    }

    protected function getDocumentInstance(): Document
    {
        return new Document();
    }

    protected function getElementInstance($value): Element
    {
        return new Element($value);
    }

    protected function getParserInstance(?Config $config = null): Parser
    {
        return new Parser([], $config);
    }

    /**
     * Extract page dimensions (width, height) from a Page object.
     * Returns array ['w' => width, 'h' => height] or null if dimensions cannot be determined.
     *
     * @param \Smalot\PdfParser\Page $page
     * @return array|null
     */
    protected function getPageDimensions($page): ?array
    {
        try {
            $details = $page->getDetails();
            if (isset($details['MediaBox'])) {
                $box = $details['MediaBox'];
                if (is_array($box) && count($box) >= 4) {
                    return [
                        'w' => (float)$box[2],
                        'h' => (float)$box[3],
                    ];
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Assert that a page has valid dimensions (both width and height > 0).
     *
     * @param \Smalot\PdfParser\Page $page
     */
    protected function assertPageHasValidDimensions($page): void
    {
        $dims = $this->getPageDimensions($page);
        $this->assertNotNull($dims, 'Page dimensions should be extractable (MediaBox)');
        $this->assertGreaterThan(0, $dims['w'], 'Page width should be > 0');
        $this->assertGreaterThan(0, $dims['h'], 'Page height should be > 0');
    }
}
