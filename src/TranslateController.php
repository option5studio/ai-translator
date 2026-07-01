<?php

namespace AiTranslator;

use AiTranslator\Jobs\TranslateContent;
use AiTranslator\Jobs\StartQueueJob;
use Statamic\Http\Controllers\CP\CpController;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Fieldset;

use Statamic\Facades\Blueprint;   
use Statamic\Facades\Collection; 
use Statamic\Facades\Content;
use App\Helpers\Utils;
use Illuminate\Support\Facades\Http;

use Artisan;
use Illuminate\Support\Facades\DB;







class TranslateController 
{
    private $apiKeyPrivate = null;
    private $service;
    public $language = null;

    

    private $translatedData;
 
    public function index($data, $siteData, $shortLocale, $isTerm)
    {
       
        $this->apiKeyPrivate = config('ai-translator.ai-translator.ai_translator_api_key');
        $apiKey = config('ai-translator.ai-translator.ai_translator_api_key');

  
        foreach($data as $row){
          
            TranslateContent::dispatch($row->id, $siteData, $this->apiKeyPrivate, $shortLocale, $isTerm);
        }

        
        return 'translating';
       
       
    }
   
    
}


