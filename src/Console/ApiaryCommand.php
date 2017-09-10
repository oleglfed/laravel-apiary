<?php

namespace oleglfed\ApiaryGenerator\Console;

use Illuminate\Console\Command;
use oleglfed\ApiaryGenerator\Generators\AbstractParser;
use oleglfed\ApiaryGenerator\Generators\RouteParser;
use File;

class ApiaryCommand extends Command
{
    protected $template = __DIR__ . '/../../resources/template';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'apiary:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates Apiary MSON';

    protected $signature = 'apiary:generate 
                             {--route= : The router to be used}
                             {--user= : The user ID to use for API response calls}
                             {--url= : Url}
                        ';

    protected $resource;
    protected $attributes;
    protected $route;
    protected $url;
    protected $user;
    protected $relationships = '';
    protected $additional = '';


    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->route = $this->option('route');
        $this->resource = ucfirst(str_singular($this->route));
        $this->setUserToBeImpersonated($this->option('user'));
        $this->url = trim($this->option('url'), '/');

        $routeParser = new RouteParser();

        //We get POST route because it has most of available attributes defined in the request
        $route = $this->getPostRoute($this->route);

        if (!$route) {
            return $this->error("Route '$this->route' is not found or does not contain POST");
        }

        $parameters = $routeParser->getParameters($route);

        $spaces = 4;

        $attributes = '';

        foreach ($parameters as $key => $parameter) {
            if (is_array($parameter)) {
                if (array_has($parameter, 'value')) {
                    $attributes .= $this->getParameterLine($key, $parameter);
                    continue;
                }

                $attributes .= "$key\n    ";
            }
        }

        $this->attributes = $attributes;

        //Write the file into /storage/apiary
        $this->write();
    }

    public function getData()
    {
        
    }

    public function getParameterLine($key, $parameter)
    {
        return "- " . $key . ": `" . $parameter['value'] . "` (" . $parameter['type'] . ($parameter['required'] ? ', required' : '') . ")\n";
    }

    /**
     * Prepare attributes
     * @param $parameters
     * @return null|string
     */
    public function prepareAttributes($parameters)
    {
        $attributes = null;

        if (count($parameters)) {
            foreach ($parameters as $name => $parameter) {
                $enum = null;

                //If enum then need to write available options
                if (array_get($parameter, 'type') == 'enum' and is_array(array_get($parameter, 'options'))) {
                    foreach (array_get($parameter, 'options') as $enumOption) {
                        $enum .= "        - $enumOption\n";
                    }
                }

                $attributes .= "    - `$name`: `"
                    . array_get($parameter, 'value')
                    . "` (" . array_get($parameter, 'type')
                    . (array_get($parameter, 'required') ? ', required' : '') . ")\n$enum";
            }
        }

        return $attributes;
    }

    /**
     * Prepare attributes
     * @param $relationships
     * @return null|string
     */
    public function prepareAdditionalObjects($relationships)
    {
        $additional = null;

        if (count($relationships)) {
            foreach ($relationships as $relationName => $relation) {
                unset($relation['type']);
                $additional .= "\n## " . ucfirst(str_singular($relationName))
                    . " [/$relationName]\n\n+ Attributes\n"
                    . $this->prepareAttributes($relation);
            }
        }

        return $additional;
    }

    /**
     * Prepare Relationships
     * @param $relationships
     * @return string
     */
    public function prepareRelationships($relationships)
    {
        $body = null;
        if (count($relationships)) {
            $body = "        + relationships\n";

            foreach ($relationships as $relationName => $relation) {
                $body .= "            - $relationName\n                - type: $relationName\n"
                    . (array_get($relation, 'type') == 'multiple'
                    ? "                - data (array[" . ucfirst(str_singular($relationName)) . "])\n\n"
                    : "                - data (" . ucfirst(str_singular($relationName)) . ")\n\n");
            }
        }

        return $body;
    }

    /**
     * Writes file based on template
     */
    public function write()
    {
        if (!File::exists(storage_path('apiary'))) {
            File::makeDirectory(storage_path('apiary'));
        }

        if (!File::exists(storage_path('apiary/' . $this->resource))) {
            File::makeDirectory(storage_path('apiary/' . $this->resource));
        }

        File::put(
            storage_path('apiary/' . $this->resource) . "/" . $this->resource,
            $this->prepare(File::get($this->template))
        );

        $this->info("Apiary document has been written to /storage/$this->resource/$this->resource");
    }

    /**
     * @param $actAs
     */
    private function setUserToBeImpersonated($actAs)
    {
        if (!empty($actAs)) {
            $this->user = app()->make(config('api-docs.user'))->find($actAs);

            if ($this->user) {
                return $this->laravel['auth']->guard()->setUser($this->user);
            }
        }
    }

    /**
     * Get POST route of resource
     * @param $routePrefix
     * @return null
     */
    private function getPostRoute($routePrefix)
    {
        $routes = \Route::getRoutes();

        foreach ($routes as $route) {
            if (in_array("POST", $route->getMethods()) and str_contains($route->getUri(), $routePrefix)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Prepare template
     * @param $fileContent
     * @return mixed
     */
    public function prepare($fileContent)
    {
        $replacings = [
            '{attributes}',
            '{route}',
            '{resource}',
            '{url}',
            '{token}',
      //      '{object}',
        ];

        $replacements = [
            $this->attributes,
            $this->route,
            $this->resource,
            $this->url,
            $this->user->getAccessToken()->getAccessToken(),
            //$this->object,
         //   $this->includes,
        ];

        return str_replace($replacings, $replacements, $fileContent);
    }
}
