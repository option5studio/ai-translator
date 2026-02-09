<?php
namespace AiTranslator;

use Statamic\Http\Controllers\CP\CpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Arr;
use Statamic\Facades\Blueprint;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint as BlueprintContract;
use Statamic\CP\PublishForm;




class SettingsController extends CpController
{
    public function index()
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/');
        } elseif (!$user->super) {
            return redirect('/');
        }

        if (class_exists('Statamic\CP\PublishForm')) {
            $blueprint = $this->getBlueprint();
            $values = [
                'translator' => env('AI_TRANSLATOR_SERVICE', 'deepl'),
                'api_key' => env('AI_TRANSLATION_API_KEY', ''),
                'free_version' => (bool) env('AI_TRANSLATION_OPTION_FREE_VERSION', false),
            ];
    
            return PublishForm::make($blueprint)
                ->title('AI Translator settings')
                ->values($values)
                ->asConfig()
                ->submittingTo(cp_route('ai-translator.config.edit'), 'POST');
        } else {
    
            $blueprint = $this->getBlueprint();
            $apiKey = env('AI_TRANSLATION_API_KEY', '');
            $translator = env('AI_TRANSLATOR_SERVICE', 'deepl');
            $freeVersion = env('AI_TRANSLATION_OPTION_FREE_VERSION', 0);

        
            $fields = $blueprint->fields()->addValues([
                'translator' => $translator,  
                'api_key' => $apiKey,  
                'free_version' => $freeVersion
            ])->preProcess();  
        
        
        
        
            return view('ai-translator::settings', [
                'blueprint' => $blueprint->toPublishArray(),
                'values' => $fields->values(),
                'meta' => $fields->meta(),
            ]);
        }
    }
    
    public function save(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->super) {
            return redirect('/');
        }
        if (class_exists('Statamic\CP\PublishForm')) {
            $blueprint = $this->getBlueprint();
            $values = PublishForm::make($blueprint)->submit($request->all());
    
            $this->setEnv('AI_TRANSLATOR_SERVICE', $values['translator'] ?? 'deepl');
            $this->setEnv('AI_TRANSLATION_API_KEY', $values['api_key'] ?? '');
            $this->setEnv('AI_TRANSLATION_OPTION_FREE_VERSION', !empty($values['free_version']) ? 'true' : 'false');
    
            return redirect(cp_route('ai-translator.config.index'))->with('success', 'Instellingen opgeslagen.');
        }else{
            $apiKey = $request->input('api_key');
            $translator = $request->input('translator');
            $freeVersion = $request->input('free_version');
            $isFreeVersion = false;
            if($freeVersion == true){
                $isFreeVersion = true;
            }
        
            $this->setEnv('AI_TRANSLATOR_SERVICE', $translator);
            $this->setEnv('AI_TRANSLATION_API_KEY', $apiKey);
            $this->setEnv('AI_TRANSLATION_OPTION_FREE_VERSION', $isFreeVersion);
    
            return redirect('/cp/ai-translator/config')->with('success', 'Instellingen opgeslagen.');
        }
    
        

    }
    

    protected function setEnv($key, $value)
    {
        $path = base_path('.env');
        $env = file_get_contents($path);

        if (strpos($env, $key) !== false) {
            $env = preg_replace("/^$key=.*/m", "$key=$value", $env);
        } else {
            $env .= "\n$key=$value";
        }

        file_put_contents($path, $env);
    }

    private function getBlueprint(): BlueprintContract
    {
        return Blueprint::make()->setContents(YAML::file(__DIR__.'/../resources/blueprints/config.yaml')->parse());
    }
}
