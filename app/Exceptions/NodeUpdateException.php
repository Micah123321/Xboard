<?php

namespace App\Exceptions;

class NodeUpdateException extends \RuntimeException
{
    public function __construct(public string $errorCode, public int $status = 409, public array $extra = [])
    {
        parent::__construct($errorCode);
    }

    public function render($request)
    {
        return new \Illuminate\Http\JsonResponse(['error' => array_merge([
            'code'=>$this->errorCode, 'message'=>$this->errorCode, 'retryable'=>in_array($this->status,[429,503]),
        ], $this->extra)], $this->status, $this->status === 429 ? ['Retry-After'=>'60'] : []);
    }
}
