<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

/**
 * What a text is embedded for. OpenAI ignores it; Voyage (input_type), Cohere (input_type) and Gemini (task_type) embed queries and documents
 * differently, so a second provider needs no change to the interface.
 */
enum Purpose
{
    case Document;
    case Query;
}
