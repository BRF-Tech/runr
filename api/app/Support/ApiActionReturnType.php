<?php

namespace App\Support;

use Dedoc\Scramble\Infer\Definition\FunctionLikeAstDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

/**
 * A controller action's responses in the API reference are those of its returns (a Scramble
 * extension, config scramble.extensions).
 *
 * Scramble (0.13) documents an action by its declared return type (JsonResponse: one bare 200
 * object) when that type does not accept the union of the action's returns, and
 * `if ($error = $this->check()) { return $error; }` puts the check's null into that union: Scramble
 * does not narrow the assignment. Without the null, and with the declared type for a return it
 * cannot infer or that the declaration does not accept (a callable's result, the model of
 * `if ($x instanceof JsonResponse) return $x;`), the action keeps its own responses: the 202 of a
 * queued install, the 201 of a new upload, the 500 next to a 200. Nothing changes for an action
 * whose returns the declared type accepts, or that declares a nullable type or none.
 */
class ApiActionReturnType implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return str_contains($type->name, '\\Http\\Controllers\\');
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        $definition = $event->getInstance()->getMethodDefinition($event->name, $event->scope);
        if (! $definition instanceof FunctionLikeAstDefinition) {
            return null;
        }
        $declared = $definition->getDeclarationDefinition()?->getReturnType();
        $inferred = $definition->getInferredReturnType();
        if (! $declared instanceof ObjectType || ! $inferred instanceof Union || $declared->accepts($inferred)) {
            return null;
        }

        // Not Union::wrap(): it takes JsonResponse and JsonResponse<…, 202> for the same type
        $types = [];
        foreach ($inferred->types as $type) {
            if ($type instanceof NullType) {
                continue;
            }
            $type = $type instanceof ObjectType && $declared->accepts($type) ? $type : $declared;
            if (! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return match (count($types)) {
            0 => null,
            1 => $types[0],
            default => new Union($types),
        };
    }
}
