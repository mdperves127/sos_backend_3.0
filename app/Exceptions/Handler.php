<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        // Handle validation exceptions with proper error messages
        if ($exception instanceof ValidationException) {
            return $this->handleValidationException($request, $exception);
        }

        // Handle HttpResponseException (from failedValidation in request classes)
        if ($exception instanceof HttpResponseException) {
            return $exception->getResponse();
        }

        return parent::render($request, $exception);
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // For API routes, return JSON response instead of redirecting
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'status' => 401
            ], 401);
        }

        // For web routes, redirect to login
        return redirect()->guest('/login');
    }

    /**
     * Handle validation exceptions with detailed error messages
     */
    protected function handleValidationException(Request $request, ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();

        return response()->json([
            'message' => 'Validation failed',
            'errors' => $errors,
            'status' => 422
        ], 422);
    }
}
