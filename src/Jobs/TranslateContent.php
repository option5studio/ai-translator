<?php

namespace AiTranslator\Jobs;
use Statamic\Facades\Search;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Illuminate\Http\Request;
use Statamic\Fieldtypes\Bard\Augmentor;

use Illuminate\Support\Facades\Config;
use Statamic\Facades\Fieldset;


use Statamic\Facades\Blueprint;   

use Statamic\Facades\Content;
use App\Helpers\Utils;
use Illuminate\Support\Facades\Http;


class TranslateContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $apiKeyPrivate = null;
    private $service;
    public $language = null;

    private $content;
    private $contentType;
    private $defaultData;
    private $localizedData;
    private $targetLocale;
    private $translatableFields;
    private $translatableData;
    private $fieldKeys;
    private $dataToTranslate;

    private $translatedData;
    private $supportedFieldtypes = [
        'array', 'bard', 'grid', 'list', 'markdown', 'redactor', 'replicator',
        'table', 'tags', 'text', 'textarea',
    ];

    private $excludedFields = [
        'terms', 
    ];
    private $excludedFieldtypes = [
        'taxonomy', 
    ];
    private $translatedContent;
    private $row;
    private $siteData;
    private $isFreeApiKeyVersion;

    private $pathToJson = [];
   

    public function __construct($row, $siteData, $apiKeyPrivate, $language)
    {
        $this->row = $row; 
        $this->siteData = $siteData;
        $this->apiKeyPrivate = $apiKeyPrivate;
       
        $this->apiKeyPrivate = $apiKeyPrivate;
        $this->language = $language;
     
       
    }


    public function config(?string $key = null, mixed $fallback = null): mixed
    {
        $config = [];

        return $key !== null
            ? ($config[$key] ?? $fallback)
            : $config;
    }
    
    public function handle()
    {
        $this->isFreeApiKeyVersion = env('AI_TRANSLATION_OPTION_FREE_VERSION');
       
        if($this->isFreeApiKeyVersion == ""){
            $this->isFreeApiKeyVersion = 0;
        }else{
            $this->isFreeApiKeyVersion = 1;

        }

        $page = Entry::query()
            ->where('id', $this->row)
            ->first();

        if (! $page) {
            return;
        }

        $targetSite = $this->siteData->handle;

        if ($page->locale() === $targetSite) {
            return;
        }

        $existingTranslation = $page->in($targetSite);

        if ($existingTranslation) {
            $newPage = $existingTranslation;
            $this->copyLocalizableFieldsFromSource($newPage, $page);
            $this->translatedContent = $newPage;
        } else {
            
            $slugIsLocalizable = $this->isSlugLocalizable($page);

            if($slugIsLocalizable){
                $slug = $this->translateWithDeepL($page->slug(), 'text');
            }else{
                $slug = $page->slug();
            }

            $newPage = $page->root()
                ->makeLocalization($targetSite)
                ->slug($slug)
                ->blueprint($page->blueprint()->handle());

            $this->copyLocalizableFieldsFromSource($newPage, $page);
            $newPage->save();

            $this->translatedContent = Entry::find($newPage->id());
        }



        $this->targetLocale = $this->siteData->locale;
        
        $this->content =  $this->translatedContent;
        
        $this->contentType = $this->content->collection()->handle();
        

        
        $this->defaultData = $this->content->data();
            
    
            
        $this->localizedData = $this->defaultData;
            

        $this->processData();
        
        $this->dumpTranslatedPaths($this->dataToTranslate);
        $this->translateExportJson();
     

                    


       
    }

    private function processData(): void
    {
        $this->getTranslatableFields();
        $this->getTranslatableData();
        $this->getFieldKeys();
        $this->getDataToTranslate();
    }

   
    private function copyLocalizableFieldsFromSource($target, $source): void
    {
        $blueprint = $source->blueprint();

        if (! $blueprint) {
            return;
        }

        $localizableFields = $blueprint->fields()->localizable()->all()->map->handle()->all();


        foreach ($localizableFields as $field) {
            $target->set($field, $source->value($field));
        }
    }

    private function isSlugLocalizable($source): bool
    {
        return in_array('slug', $source->blueprint()->fields()->localizable()->all()->map->handle()->all());
    }

    private function dumpTranslatedPaths(array $data = [], $path = ''): void
    {
        foreach ($data as $key => $value) {
            $currentPath = $path ? $path . '.' . $key : $key;
            
            $isProsemirrorNode = isset($value['type']) && isset($value['content'])
                && !isset($value['text'])
                && !isset($value['title']);

            if ($isProsemirrorNode) {
                $bardContent = $value;

                $html = (new Augmentor($this))->renderProsemirrorToHtml([
                    'type' => $bardContent['type'],
                    'content' => $bardContent['content'],
                ]);

                $this->pathToJson[$currentPath . '.bard'] = $html;
            } elseif (is_array($value)) {
                    $this->dumpTranslatedPaths($value, $currentPath);
                
            } else{
                if ($this->isTranslatableKeyValuePair($value, $key)) {
                    $this->pathToJson[$currentPath . '.text'] = $value;
                } 
            }

        }
        
        $this->pathToJson['slug'] = $this->content->slug;

    }

   

     private function translateExportJson()
    {
       
       
        $translatedItems = $this->pathToJson;

        foreach ($translatedItems as $key => $value) {
            $path = $key;

            $translatedValue = $this->translateWithDeepL($value, 'text');
            $this->setTranslatedValueByPath($this->dataToTranslate, $path, $translatedValue);

        }
       
        foreach ($this->dataToTranslate as $key => $value) {
           
            $this->content->set($key, $value);
            
        }
        
        $slug = $translatedItems['slug'];
    
       
        $this->content->slug($slug);
        $this->content->save();

       

    }

    private function setTranslatedValueByPath(&$entry, string $path, $value): void
    {
        $refs = &$entry;
        
    
        $keys = $this->pathStringToArrayKeys($path);
        $type = array_pop($keys); 
        $lastKey = array_pop($keys); 
    
        if ($type === 'text') {
            $toSetValue = $value;
        } elseif ($type === 'bard') {
            $html = is_string($value) && trim($value) !== '' ? $value : '<p></p>';
            $toSetValue = (new Augmentor($this))->renderHtmlToProsemirror($html)['content'];
        } else {
            $toSetValue = $value;
        }
    
        foreach ($keys as $key) {
            if (is_numeric($key)) {
                $key = (int)$key;
            }
    
            if (!isset($refs[$key])) {
                $refs[$key] = [];
            }
    
            $refs = &$refs[$key]; 
        }
    
        if ($type === 'text') {
            $refs[$lastKey] = $toSetValue;
        } elseif ($type === 'bard') {
            if (isset($toSetValue[0]['content']) && count($toSetValue) > 1) {
                $refs[$lastKey]['content'] = $toSetValue;
            } elseif (isset($toSetValue[0]['content'])) {
                $refs[$lastKey]['content'] = $toSetValue[0]['content'];
            } else {
                $refs[$lastKey]['content'] = $toSetValue;
            }
        }
    }
    


    function pathStringToArrayKeys($path) {
        $parts = explode('.', $path);
        

        

        $result = [];
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $result[] = (int) $part; 
            } else {
                $result[] = $part;
            }
        }
        return $result;
    }

    

    private function getLocalizableFields(): array
    {
        // Get the fields from the blueprint.
        $fields = collect($this->content->blueprint()->fields()->all());
    
        // Get the fields where "localizable: true".
        $localizableFields = $fields->filter(function ($field) {
            return isset($field->config()['localizable']) && $field->config()['localizable'] === true;
        });

        return $localizableFields->toArray();
    }

  

  

    private function getTranslatableData(): void
    {
        // Ensure $this->defaultData is an array
        $this->defaultData = $this->defaultData->toArray();

       

        $this->translatableData = array_intersect_key($this->defaultData, $this->translatableFields);
    }

    private function getTranslatableFields()
    {
        $localizableFields = $this->getLocalizableFields();
  
       
        $this->translatableFields = $localizableFields;
    }

    private function getFieldKeys(): void
    {
        $this->fieldKeys = [
            'allKeys' => $this->getTranslatableFieldKeys($this->translatableFields),
            'setKeys' => $this->getTranslatableSetKeys($this->translatableFields),
        ];
        
        if(!isset( $this->fieldKeys['allKeys']['text'])){
            $this->fieldKeys['allKeys']['text'] = [];
        }
        

       
      
    }

   
  
    private function getDataToTranslate(): void
    {
        // Ensure $this->translatableData and $this->localizedData are arrays
        $translatableData = is_array($this->translatableData) ? $this->translatableData : $this->translatableData->toArray();
        $localizedData = is_array($this->localizedData) ? $this->localizedData : $this->localizedData->toArray();

        $mergedData = array_replace_recursive($translatableData, $localizedData);

        $this->dataToTranslate = $this->unsetSpecialFields($mergedData);
       
    }
    
    private function unsetSpecialFields(array $array): array
    {
        
        if ($this->contentType === 'entry') {
            unset($array['slug']);
        }

        
        if ($this->contentType === 'page') {
            unset($array['slug']);
        }

        
        unset($array['id']);

        return $array;
    }

   

    private function getTranslatableFieldKeys(array $fields): array
    {
        $result = [];
    
        foreach ($fields as $key => $field) {
          
            if (isset($field['type'])) {
            
                if ($field['type'] === 'text' || $field['type'] === 'textarea' ) {
                    $result[$key] = $key;
                }

            
                if (isset($field['sets']) || (isset($field['field']) && isset($field['field']['sets']))) {
                    $sets = $field['sets'] ?? $field['field']['sets'] ?? [];

                    foreach ($sets as $setKey => $set) {
                        if (isset($set['sets'])) {
                            foreach ($set['sets'] as $nestedSetKey => $nestedSet) {
                                if (isset($nestedSet['fields'])) {
                                    $result[$key][$setKey][$nestedSetKey] = $this->getTranslatableFieldKeys($nestedSet['fields']);
                                }
                            }
                        }

                        if (isset($set['fields'])) {
                            $result[$key][$setKey] = $this->getTranslatableFieldKeys($set['fields']);
                        } elseif (is_array($set)) {
                            $result[$key][$setKey] = $this->getTranslatableFieldKeys($set);
                        }
                    }
                }


                if (isset($field['fields'])) {
                    $result[$key] = array_merge($result[$key] ?? [], $this->getTranslatableFieldKeys($field['fields']));
                }
            }else if(is_array($field) && isset($field['import'])){
                $importedFieldsetName = $field['import'];
                $importedFieldset = Fieldset::find($importedFieldsetName);

                if ($importedFieldset) {
                    $importedFieldsetFields = $importedFieldset->fields()->all()->toArray();
                    $importedKeys = $this->getTranslatableFieldKeys($importedFieldsetFields);

                    if (isset($field['prefix'])) {
                        $prefix = $field['prefix'];
                        $prefixedKeys = [];

                        foreach ($importedKeys as $k => $v) {
                            if (is_array($v)) {
                                $nestedPrefixed = [];
                                foreach ($v as $nk => $nv) {
                                    $nestedPrefixed["{$prefix}{$nk}"] = is_string($nv) ? "{$prefix}{$nv}" : $nv;
                                }
                                $prefixedKeys["{$prefix}{$k}"] = $nestedPrefixed;
                            } else {
                                $prefixedKeys["{$prefix}{$k}"] = is_string($v) ? "{$prefix}{$v}" : $v;
                            }
                        }

                        $result = array_merge($result, $prefixedKeys);
                    } else {
                        $result = array_merge($result, $importedKeys);
                    }

                } else {
                    $result[$importedFieldsetName] = null;
                }
            
            } elseif (is_array($field)) {
                foreach ($field as $nestedKey => $nestedField) {
                    if (isset($nestedField['type']) && $nestedField['type'] === 'text') {
                        if (isset($field['handle']) && isset($field['field']['localizable']) && $field['field']['localizable'] === true) {
                            
                            if (!is_numeric($field['handle'])) {
                                $result[$field['handle']] = [];
                            }
                        }
                    } else if (isset($field['handle']) && !is_array($field['field'])) {
                    
                        [$fieldsetName, $fieldName] = explode('.', $field['field']);
                        
                        $fieldset = Fieldset::find($fieldsetName);
                    
                        if ($fieldset) {
                            $fieldsetFields = $fieldset->fields()->all()->toArray();
                        
                        
                            if (isset($fieldsetFields[$fieldName]['fields'])) {
                                $result = array_merge($result, $this->getTranslatableFieldKeys($fieldsetFields[$fieldName]['fields']));
                            } elseif (is_array($fieldsetFields[$fieldName])) {
                                $result = array_merge($result, $this->getTranslatableFieldKeys($fieldsetFields[$fieldName]));
                            }
                        } else {
                        
                            $result[$field['handle']] = [];
                        }
                    }else if(isset($field['import'])){
                        $importedFieldsetName = $field['import'];
                        $importedFieldset = Fieldset::find($importedFieldsetName);
                       

                        
                        if ($importedFieldset) {
                            $importedFieldsetFields = $importedFieldset->fields()->all()->toArray();
                           

                            
                            $result = array_merge($result, $this->getTranslatableFieldKeys($importedFieldsetFields));
                        } else {
                           
                            $result[$importedFieldsetName] = null;
                        }
                    } elseif (isset($nestedField['fields'])) {
                    
                        $result = array_merge($result, $this->getTranslatableFieldKeys($nestedField['fields']));
                    } elseif (is_array($nestedField)) {
                        
                        $result = array_merge($result, $this->getTranslatableFieldKeys($nestedField));
                    }
                }
            }
        }

        return $result;
    }

    

    private function getTranslatableSetKeys(array $fields): array
    {
        $result = [];

        foreach ($fields as $key => $field) {
            if (isset($field['type'])) {
            
                if (in_array($field['type'], ['replicator', 'bard'])) {
                    if (isset($field['sets'])) {
                        foreach ($field['sets'] as $setKey => $set) {
                            if (isset($set['fields'])) {
                                $result[$key][$setKey] = $this->getTranslatableSetKeys($set['fields']);
                            }
                        }
                    }
                } elseif (isset($field['fields'])) {
                    $result[$key] = $this->getTranslatableSetKeys($field['fields']);
                } else {
                    $result[$key] = $key;
                }
            } elseif (is_array($field)) {
                foreach ($field as $nestedKey => $nestedField) {
                    if (isset($nestedField['type']) && in_array($nestedField['type'], ['replicator', 'bard'])) {
                        if (isset($nestedField['sets'])) {
                            foreach ($nestedField['sets'] as $setKey => $set) {
                                if (isset($set['fields'])) {
                                    $result[$nestedKey][$setKey] = $this->getTranslatableSetKeys($set['fields']);
                                }
                            }
                        }
                    } elseif (isset($nestedField['fields'])) {
                        $result[$nestedKey] = $this->getTranslatableSetKeys($nestedField['fields']);
                    } else {
                        $result[$nestedKey] = $nestedKey;
                    }
                }
            }
        }

        return $result;
    }




    private function translateWithDeepL(string $text, string $format): string
    {
        $hasSpace = substr($text, -1) === ' ';

      

        $postData = [
            'text' => $text,
            'target_lang' => $this->language
        ];

        $url = $this->isFreeApiKeyVersion == 1
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: DeepL-Auth-Key ' . $this->apiKeyPrivate,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
       

        if (isset($result['translations'][0]['text'])) {
            $translatedText = $result['translations'][0]['text'];
            
            if ($hasSpace) {
                $translatedText .= ' ';
            }

            return $translatedText;
        } else {
            return $text;
        }
    }


    private function isTranslatableKeyValuePair($value, string $key): bool
    {
      
       
        // Skip empty $value.
        if (empty($value)) {
            return false;
        }

        // Skip numeric $value.
        if (is_numeric($value)) {
            return false;
        }
        // temp solution for not translating taxonomies
        if (is_numeric($key)) {
            return false;
        }

        // Skip boolean $value.
        if (is_bool($value)) {
            return false;
        }

        // Skip 'type: $value', where $value is a Bard/Replicator set key.
        if ($key === 'type' && $this->arrayKeyExistsRecursive($value, $this->fieldKeys['setKeys'])) {
            return false;
        }

        // Skip if $key doesn't exists in the fieldset.
        if (! $this->arrayKeyExistsRecursive($key, $this->fieldKeys['allKeys']) && ! is_numeric($key)) {
            return false;
        }

        if (in_array($key, $this->excludedFields)) {
            return false;
        }


        if (in_array($key, $this->excludedFields)) {
            return false;
        }

        

        return true;
    }


    /**
     * Recursively check if a key exists in an array.
     *
     * @param mixed $key
     * @param array $array
     * @return bool
     */
    private function arrayKeyExistsRecursive($key, array $array): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        } else {
            foreach ($array as $nested) {
                if (is_array($nested) && $this->arrayKeyExistsRecursive($key, $nested)) {
                    return true;
                }
            }
        }

        return false;
    }
   
}