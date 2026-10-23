<?php

namespace App\Support\Projects;

use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A request on a project that another request deleted while it ran: the rows it reads and writes
 * went with the project (its config row, triggers, regions …), so what fails then — a write
 * against a row that is gone, a foreign key to the project — is the deletion, not the request.
 * Such an error answers 404 as for an unknown project (Project::notFound()), not a server
 * error, and is not reported (bootstrap/app.php maps every exception through map()).
 *
 * The paths that load a project's row and write it check for this themselves
 * (ProjectService::updateConfigRow(), the controllers' fresh()); this catches the rest.
 */
final class DeletedProject
{
    /**
     * $e as the API answers it: the 404 of the request's project when that project no longer
     * exists and $e would answer a server error, else $e unchanged.
     */
    public static function map(Throwable $e, ?Request $request = null): Throwable
    {
        if (self::answered($e)) {
            return $e;
        }

        $request ??= app()->bound('request') ? app('request') : null;
        $project = $request instanceof Request ? $request->route('project') : null;
        // {project}: the bound model, or the id of a controller that loads it itself (as
        // AuthorizeApiRoute reads it)
        $id = match (true) {
            $project instanceof Project => (string) $project->getKey(),
            is_string($project) && $project !== '' => $project,
            default => null,
        };
        if ($id === null) {
            return $e;
        }

        try {
            if (Project::query()->whereKey($id)->exists()) {
                return $e;
            }
        } catch (Throwable) {
            // the database does not answer: the error stands
            return $e;
        }

        $gone = $project instanceof Project ? $project : (new Project)->forceFill(['id' => $id]);

        return $gone->notFound($e);
    }

    /**
     * An exception with an answer of its own (a 4xx, or one it renders itself): not the deletion's
     * doing. The handler maps before it prepares, so these come unprepared too.
     */
    private static function answered(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface
            || $e instanceof HttpResponseException
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof RecordsNotFoundException
            || $e instanceof RecordNotFoundException
            || $e instanceof BackedEnumCaseNotFoundException
            || $e instanceof TokenMismatchException
            || $e instanceof RequestExceptionInterface
            || $e instanceof Responsable
            || method_exists($e, 'render');
    }
}
