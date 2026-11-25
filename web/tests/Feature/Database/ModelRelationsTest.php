<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_relation_executes_against_the_schema(): void
    {
        $checked = 0;

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }

            $model = new $class;

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->getNumberOfParameters() > 0 || $method->isStatic()) {
                    continue;
                }

                $returnType = $method->getReturnType()?->getName();

                if (! $returnType || ! is_subclass_of($returnType, Relation::class) || $returnType === MorphTo::class) {
                    continue;
                }

                $model->{$method->name}()->limit(1)->get();
                $checked++;
            }
        }

        $this->assertGreaterThan(100, $checked);
    }
}
