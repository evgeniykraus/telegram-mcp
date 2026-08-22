<?php

namespace App\Mcp\Resources;

use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('analytics://channels')]
#[Description('List of all tracked social media and Telegram channels')]
#[MimeType('application/json')]
class ChannelsResource extends Resource
{
    public function handle(Request $request, SourceRepositoryInterface $sourceRepository): Response|ResponseFactory
    {
        $channels = $sourceRepository->all();

        return Response::structured([
            'channels' => $channels->toArray(),
        ]);
    }
}
