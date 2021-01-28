<?php
declare(strict_types=1);
namespace Neos\EventSourcedNeosAdjustments\Tests\Functional\EventSourcedRouting\Http;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\DimensionSpace\Dimension;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\EventSourcedNeosAdjustments\EventSourcedRouting\Http\ContentDimensionDetection\Exception\InvalidContentDimensionValueDetectorException;
use Neos\Flow\Http;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\EventSourcedNeosAdjustments\EventSourcedRouting\Http\BasicContentDimensionResolutionMode;
use Neos\EventSourcedNeosAdjustments\EventSourcedRouting\Http\DetectContentSubgraphMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DetectContentSubgraphMiddlewareTest extends FunctionalTestCase
{
    /**
     * @var RouteParameters|null
     */
    private $routeParameters;

    /**
     * @var RequestHandlerInterface|MockObject
     */
    private $mockNextMiddleware;

    public function setUp(): void
    {
        parent::setUp();

        $world = new Dimension\ContentDimensionValue('WORLD', null, [], ['resolution' => ['value' => 'com']]);
        $greatBritain = new Dimension\ContentDimensionValue('GB', new Dimension\ContentDimensionValueSpecializationDepth(1), [], ['resolution' => ['value' => 'co.uk']]);
        $germany = new Dimension\ContentDimensionValue('DE', new Dimension\ContentDimensionValueSpecializationDepth(1), [], ['resolution' => ['value' => 'de']]);

        $defaultSeller = new Dimension\ContentDimensionValue('default', null, [], ['resolution' => ['value' => 'default']]);
        $sellerA = new Dimension\ContentDimensionValue('sellerA', new Dimension\ContentDimensionValueSpecializationDepth(1), [], ['resolution' => ['value' => 'sellerA']]);

        $defaultChannel = new Dimension\ContentDimensionValue('default', null, [], ['resolution' => ['value' => 'default']]);
        $channelA = new Dimension\ContentDimensionValue('channelA', new Dimension\ContentDimensionValueSpecializationDepth(1), [], ['resolution' => ['value' => 'channelA']]);

        $english = new Dimension\ContentDimensionValue('en', null, [], ['resolution' => ['value' => 'en']]);
        $german = new Dimension\ContentDimensionValue('de', null, [], ['resolution' => ['value' => 'de']]);

        $contentDimensions = [
            'market' => new Dimension\ContentDimension(
                new Dimension\ContentDimensionIdentifier('market'),
                [
                    $world->getValue() => $world,
                    $greatBritain->getValue() => $greatBritain,
                    $germany->getValue() => $germany
                ],
                $world,
                [
                    new Dimension\ContentDimensionValueVariationEdge($greatBritain, $world),
                    new Dimension\ContentDimensionValueVariationEdge($germany, $world)
                ],
                [
                    'resolution' => [
                        'mode' => BasicContentDimensionResolutionMode::RESOLUTION_MODE_HOSTSUFFIX
                    ]
                ]
            ),
            'seller' => new Dimension\ContentDimension(
                new Dimension\ContentDimensionIdentifier('seller'),
                [
                    $defaultSeller->getValue() => $defaultSeller,
                    $sellerA->getValue() => $sellerA
                ],
                $defaultSeller,
                [
                    new Dimension\ContentDimensionValueVariationEdge($sellerA, $defaultSeller)
                ],
                [
                    'resolution' => [
                        'options' => [
                            'allowEmptyValue' => true
                        ]
                    ]
                ]
            ),
            'channel' => new Dimension\ContentDimension(
                new Dimension\ContentDimensionIdentifier('channel'),
                [
                    $defaultChannel->getValue() => $defaultChannel,
                    $channelA->getValue() => $channelA
                ],
                $defaultChannel,
                [
                    new Dimension\ContentDimensionValueVariationEdge($channelA, $defaultChannel)
                ],
                [
                    'resolution' => [
                        'options' => [
                            'allowEmptyValue' => true
                        ]
                    ]
                ]
            ),
            'language' => new Dimension\ContentDimension(
                new Dimension\ContentDimensionIdentifier('language'),
                [
                    $english->getValue() => $english,
                    $german->getValue() => $german
                ],
                $english,
                [],
                [
                    'resolution' => [
                        'mode' => BasicContentDimensionResolutionMode::RESOLUTION_MODE_HOSTPREFIX,
                        'options' => [
                            'allowEmptyValue' => true
                        ]
                    ]
                ]
            )
        ];

        $dimensionPresetSource = $this->objectManager->get(Dimension\ContentDimensionSourceInterface::class);
        $this->inject($dimensionPresetSource, 'contentDimensions', $contentDimensions);

        $this->mockNextMiddleware = $this->getMockBuilder(RequestHandlerInterface::class)->getMock();
        $this->mockNextMiddleware->method('handle')->willReturnCallback(function (ServerRequestInterface $modifiedRequest) {
            $this->routeParameters = $modifiedRequest->getAttribute(Http\ServerRequestAttributes::ROUTING_PARAMETERS);
            return new Response();
        });
    }


    /**
     * @test
     * @throws InvalidContentDimensionValueDetectorException
     */
    public function handleAddsCorrectSubgraphIdentityToComponentContextWithAllDimensionValuesGivenLiveWorkspaceAndDefaultDelimiter()
    {
        $uri = new Uri('https://de.domain.com/sellerA_channelA/home.html');
        $request = new ServerRequest('GET', $uri);

        $detectContentSubgraphMiddleware = new DetectContentSubgraphMiddleware();
        $detectContentSubgraphMiddleware->process($request, $this->mockNextMiddleware);

        self::assertNull($this->routeParameters->getValue('workspaceName'));
        self::assertSame(1, $this->routeParameters->getValue('uriPathSegmentOffset'));

        $expectedDimensionSpacePoint = new DimensionSpacePoint([
            'market' => 'WORLD',
            'seller' => 'sellerA',
            'channel' => 'channelA',
            'language' => 'de'
        ]);
        self::assertEquals(
            $expectedDimensionSpacePoint,
            $this->routeParameters->getValue('dimensionSpacePoint')
        );
    }

    /**
     * @test
     */
    public function handleAddsCorrectSubgraphIdentityToComponentContextWithAllDimensionValuesGivenLiveWorkspaceAndModifiedDelimiter()
    {
        $uri = new Uri('https://de.domain.com/sellerA-channelA/home.html');
        $request = new ServerRequest('GET', $uri);

        $detectContentSubgraphMiddleware = new DetectContentSubgraphMiddleware();
        $this->inject($detectContentSubgraphMiddleware, 'uriPathSegmentDelimiter', '-');
        $detectContentSubgraphMiddleware->process($request, $this->mockNextMiddleware);

        self::assertNull($this->routeParameters->getValue('workspaceName'));
        self::assertSame(1, $this->routeParameters->getValue('uriPathSegmentOffset'));

        $expectedDimensionSpacePoint = new DimensionSpacePoint([
            'market' => 'WORLD',
            'seller' => 'sellerA',
            'channel' => 'channelA',
            'language' => 'de'
        ]);
        self::assertEquals(
            $expectedDimensionSpacePoint,
            $this->routeParameters->getValue('dimensionSpacePoint')
        );
    }

    /**
     * @test
     * @throws InvalidContentDimensionValueDetectorException
     */
    public function handleAddsCorrectSubgraphIdentityToComponentContextWithMinimalDimensionValuesGivenLiveWorkspaceAndModifiedDelimiter()
    {
        $uri = new Uri('https://domain.com/home.html');
        $request = new ServerRequest('GET', $uri);

        $detectContentSubgraphMiddleware = new DetectContentSubgraphMiddleware();
        $detectContentSubgraphMiddleware->process($request, $this->mockNextMiddleware);
        self::assertNull($this->routeParameters->getValue('workspaceName'));
        self::assertSame(0, $this->routeParameters->getValue('uriPathSegmentOffset'));

        $expectedDimensionSpacePoint = new DimensionSpacePoint([
            'market' => 'WORLD',
            'seller' => 'default',
            'channel' => 'default',
            'language' => 'en'
        ]);
        self::assertEquals(
            $expectedDimensionSpacePoint,
            $this->routeParameters->getValue('dimensionSpacePoint')
        );
    }
}
