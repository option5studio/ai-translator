<?php

namespace AiTranslator\Actions;

use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use AiTranslator\TranslateController;
use Statamic\Contracts\Taxonomies\Term;



class SelectEntriesToTranslate extends Action
{
    protected $translationFailed = false;

    public $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 5h16M9 3v2m6-2v2M10 9h4m0 0c-.5 2.5-2 5-4 6.5M14 9c.5 2.5 2 5 4 6.5"/>
                    <path d="M5 20h6m-3-3v6"/>
                </svg>';

                
    public static function title()
    {
        return __('Translate');
    }

    public function visibleTo($item)
    {
    
        if (! $this->isMultisite()) {
            return false;
        }
       
        if ($item instanceof Term && ($this->context['view'] ?? null) === 'form') {
            return false;
        }
        return $item instanceof Entry || $item instanceof Term;
    }

    public function visibleToBulk($items)
    {
        return $this->isMultisite() && ($items->every(fn ($item) => $item instanceof Entry || $item instanceof Term));
    }



    public function bypassesDirtyWarning(): bool
    {
        return true;
    }

    protected function fieldItems()
    {
        $run = $this->isMultisite();
        if($run) {
            $currentSite = Site::selected()->handle();
   
        
            $sites = Site::all();
    
            return [
                'warning' => [
                    'type' => 'html',
                    'html' => '<div class=" text-sm text-red-500 rounded">
                                ' . __('The page will refresh after translating, unsaved changes will be lost.') . '
                            </div>',
                ],
                'language' => [
                    'type' => 'select',
                    'label' => __('Pick a language'),
                    'options' => $sites->mapWithKeys(function ($site) {
                        return [$site->locale => $site->locale];
                    })->toArray(),
                    'default' => $sites->first()->locale, 
                ]
            ];
        }
       
    }

    public function run($items, $values)
    {
        $run = $this->isMultisite();
        if(!$run) {
            return __('This action is not available for single site installations.');
        }

        $currentSite = Site::selected()->handle();
       
        
        $sites = Site::all();

        $chosenLanguage = $values['language']; 

        $siteData = $sites->firstWhere('locale', $chosenLanguage);
        $shortLocale = $siteData->short_locale;


        $isTerm = false;
        

        foreach($items ?? [] as $item){
            $locale = $item->locale;

            if($item instanceof Term){
                $isTerm = true;
            }

            if($locale == $siteData->handle){
                $this->translationFailed = true;
                throw new \Exception(__('This entry is already in the selected language and cannot be translated.'));
            }
            break;
        }
       
      
        if ($siteData) {
            $controller = new TranslateController();
            $controller->index($items, $siteData, $shortLocale, $isTerm);
            
        } else {
            return __('Something went wrong');
        }
    }

    public function redirect($items, $values)
    {
        if ($this->translationFailed) {
            return false;
        }

        $isQueued = config('queue.default') !== 'sync';

        if ($isQueued) {
            session()->flash('info', __('Translation is queued and will run in the background.'));
        } else {
            session()->flash('success', __('Translation is completed.'));
        }

        return request()->header('referer'); 
    }

    private function isMultisite()
    {   
        return Site::all()->count() > 1;
    }
}
