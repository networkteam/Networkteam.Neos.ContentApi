<?php
declare(strict_types=1);

namespace Networkteam\Neos\ContentApi\Tests\Unit\Fusion\Backend;

use Networkteam\Flow\CommandMigrations\ValueObject\CommandDefinitionId;
use Neos\Flow\Tests\UnitTestCase;
use Networkteam\Neos\ContentApi\Fusion\Backend\ParseIncludesImplementation;

class ParseIncludesImplementationTest extends UnitTestCase
{

    public function parseHTMLExamples()
    {

        $html = <<<HTML
        <script>window.neos = window.parent.neos;</script>
        <script src="http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/JavaScript/Vendor.js"></script>
        <script src="http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui/Plugins/ckeditor/ckeditor.js"></script>
        <script src="http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/JavaScript/Guest.js"></script>
        <link rel="stylesheet" href="http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/Styles/Host.css">
        <link rel="stylesheet" href="http://localhost:3000/_Resources/Static/Packages/Shel.Neos.Hyphens/HyphensEditor/Plugin.css" />
        HTML;

        return [
            [
                $html,
                [
                    ['key' => 'script-1', 'type' => 'script', 'content' => 'window.neos = window.parent.neos;'],
                    ['key' => 'script-2', 'type' => 'script', 'src' => 'http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/JavaScript/Vendor.js'],
                    ['key' => 'script-3', 'type' => 'script', 'src' => 'http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui/Plugins/ckeditor/ckeditor.js'],
                    ['key' => 'script-4', 'type' => 'script', 'src' => 'http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/JavaScript/Guest.js'],
                    ['key' => 'link-5', 'type' => 'link', 'href' => 'http://localhost:3000/_Resources/Static/Packages/Neos.Neos.Ui.Compiled/Styles/Host.css', 'rel' => 'stylesheet'],
                    ['key' => 'link-6', 'type' => 'link', 'href' => 'http://localhost:3000/_Resources/Static/Packages/Shel.Neos.Hyphens/HyphensEditor/Plugin.css', 'rel' => 'stylesheet']
                ]
            ],
        ];
    }

    /**
     * @param string $html
     * @param array $expectedIncludes
     * @test
     * @dataProvider parseHTMLExamples
     */
    public function parseHTMLTests(string $html, array $expectedIncludes)
    {
        $includes = ParseIncludesImplementation::parseHTML($html);
        $this->assertEquals($expectedIncludes, $includes);
    }
}
