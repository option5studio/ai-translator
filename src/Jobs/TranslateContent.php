<?php

namespace AiTranslator\Jobs;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Content;


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


    public function config(): array
    {
        return [];
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

        if($page->locale == $this->siteData->handle){
            return;
        }



        $pageLocalizations = $page->descendants();
        $origin = null;
        if ($page->origin()) {
            $origin = Entry::query()->where('id', $page->origin()->id)->first();
                if($origin){
                    if($origin->locale == $this->siteData->handle){
                       $pageLocalizations = [$origin];


                    }
                }

        }



        $translatedPageExists = false;
        $newPage = null;

        foreach($pageLocalizations as $pageTranslation){
            if($pageTranslation->locale == $this->siteData->handle){
                $translatedPageExists = true;
                $newPage = $pageTranslation;

                $newPage->data($page->data());

                $this->translatedContent = $newPage;
                break;

            }
        }

        if($translatedPageExists == false){

            $slug = $page->slug();

            $response = $this->translateWithDeepl($slug, $this->apiKeyPrivate);
            if (isset($response['translations'][0]['text'])) {
                $slug = $response['translations'][0]['text'];

            }

            $newPage =  Entry::make()
                ->slug($slug)
                ->origin($page->id)
                ->locale($this->siteData->handle)
                ->collection(Collection::findByHandle($page->collection->handle))
                ->data($page->data())
                ->blueprint($page->blueprint->handle);


            $newPage->save();

            $this->translatedContent = Entry::find($newPage->id);
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

    private function dumpTranslatedPaths(array $data = [], $path = ''): void
    {
        foreach ($data as $key => $value) {
            $currentPath = $path ? $path . '.' . $key : $key;

            if ((isset($value['type']) && isset($value['content']))) {


                $bardContent =  $value;

                $html = (new Augmentor($this))->renderProsemirrorToHtml([
                    'type' => $bardContent['type'],
                    'content' => $bardContent['content'],
                ]);


                $this->pathToJson[$currentPath . '.bard'] = $html;

            }else if(is_array($value)) {
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
            $toSetValue = (new Augmentor($this))->renderHtmlToProsemirror($value)['content'];
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

        // Add the title field, so it can be translated.
        if ($this->contentType !== 'globals' && ! $localizableFields->has('title')) {
            $localizableFields->put('title', [
                'type' => 'text',
                'localizable' => true,
            ]);
        }
        return $localizableFields->toArray();
    }

    private function getTranslatableData(): void
    {
        // Ensure $this->defaultData is an array
        $this->defaultData = $this->defaultData->toArray();

        // Ensure $this->translatableFields is an array
        // $this->translatableFields = $this->translatableFields->toArray();

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

    // private function filterSupportedFieldtypes(array $fields): array
    // {

    //     return collect($fields)
    //         ->map(function ($item) {
    //             if (!isset($item['type'])) {
    //                 return null;
    //             }

    //             switch ($item['type']) {
    //                 case 'replicator':
    //                 case 'bard':
    //                     $item['sets'] = collect($item['sets'] ?? [])
    //                         ->map(function ($set) {
    //                             if (isset($set['fields'])) {
    //                                 $set['fields'] = $this->filterSupportedFieldtypes($set['fields']);
    //                             }
    //                             return $set;
    //                         })
    //                         ->filter(function ($set) {
    //                             return isset($set['fields']) && count($set['fields']) > 0;
    //                         })
    //                         ->toArray();
    //                     break;
    //                 case 'grid':
    //                     if (isset($item['fields'])) {
    //                         $item['fields'] = $this->filterSupportedFieldtypes($item['fields']);
    //                     }
    //                     break;
    //             }

    //             return $item;
    //         })
    //         ->filter(function ($item) {
    //             if (!isset($item['type'])) {
    //                 return false;
    //             }

    //             $supported = in_array($item['type'], $this->supportedFieldtypes);

    //             if (!$supported) {
    //                 return false;
    //             }

    //             switch ($item['type']) {
    //                 case 'replicator':
    //                     return isset($item['sets']) && count($item['sets']) > 0;
    //                 case 'grid':
    //                     return isset($item['fields']) && count($item['fields']) > 0;
    //                 default:
    //                     return true;
    //             }
    //         })
    //         ->filter()
    //         ->toArray();
    // }

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

        if ($this->isFreeApiKeyVersion == 1) {
            $url = 'https://api-free.deepl.com/v2/translate';
        } else {
            $url = 'https://api.deepl.com/v2/translate';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: DeepL-Auth-Key ' . $this->apiKeyPrivate
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

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

        // Skip if $value is in the target locale.
        // if ($this->service->detectLanguage($value) === $this->targetLocale) {
        //     return false;
        // }

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