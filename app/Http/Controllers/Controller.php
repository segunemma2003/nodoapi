<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="Business Management API",
 *     version="1.0.0",
 *     description="Laravel 11 Business Management API with User Model and Sanctum Authentication",
 *     @OA\Contact(
 *         email="admin@businessapi.com",
 *         name="API Support"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Local Development Server"
 * )
 * @OA\Server(
 *     url="https://nodopayapi-419811b691e7.herokuapp.com",
 *     description="Production Server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="sanctumAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Token",
 *     description="Enter your Sanctum authentication token"
 * )
 */

abstract class Controller
{

}
