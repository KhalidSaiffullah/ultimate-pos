<?php

namespace App\Http\Controllers\Api\Storefront;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use App\Contact;

class ApiController extends Controller
{
    protected $statusCode;
    protected $perPage;
    protected $business_id;

    public function __construct() {
        $this->perPage = 30;
        $this->business_id = request()->header('business_id');
    }

    public function getStatusCode(){
        return $this->statusCode;
    }

    public function setStatusCode($statusCode){
        $this->statusCode = $statusCode;
        return $this;
    }

    public function respondUnauthorized($message = 'Unauthorized'){
        return $this->setStatusCode(403)->respondWithError($message);
    }

    public function respond($data){
        return response()->json($data);
    }

    public function modelNotFoundExceptionResult($e) {
        return $this->setStatusCode(404)->respondWithError($e->getMessage());

        // return [
        //         'status' => 404,
        //         'class' => method_exists($e, 'getModel') ? $e->getModel() : '',
        //         'value' => method_exists($e, 'getIds') ? $e->getIds() : '',
        //         'message' => 
        //     ];
    }

    public function otherExceptions($e) {
        $msg = is_object($e) ? $e->getMessage() : $e;
        return $this->setStatusCode(400)->respondWithError($msg);

        // return [
        //         'status' => 400,
        //         'message' => $e->getMessage()
        //     ];
    }

    protected function respondWithError($message){
        return response()->json([
                'error' => [
                    'message' => $message
                ]
            ], $this->getStatusCode());
    }

    /**
     * Retrieves current passport client from request
     */
    public function getClient()
    {
        $bearerToken = request()->bearerToken();
        $tokenId = (new \Lcobucci\JWT\Parser())->parse($bearerToken)->getHeader('jti');
        $client = \Laravel\Passport\Token::find($tokenId)->client;

        return $client;
    }

    public function getContact()
    {
        $contact = Contact::where('api_token',request()->header('Authorization'))
                ->where('business_id',request()->header('business_id'))
                ->first();
        
        if(!$contact){
            return null;
        }
        return $contact->id;
    }
}
