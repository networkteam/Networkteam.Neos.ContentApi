<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\Error\Messages\Error;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Fusion\Exception;

trait ErrorHandlingTrait
{

    /**
     * @var ActionResponse
     */
    protected $response;

    /**
     * @var Arguments
     */
    protected $arguments;

    protected function errorAction()
    {
        $this->response->setContentType('application/json');

        $this->response->setStatusCode(400);
        return json_encode([
            'errors' => array_map(function (array $errors) {
                return array_map(function (Error $error) {
                    return [
                        'message' => $error->getMessage(),
                        'code' => $error->getCode()
                    ];
                }, $errors);
            }, $this->arguments->getValidationResults()->getFlattenedErrors())
        ]);
    }

    protected function respondWithErrors(\Throwable $throwable): void
    {
        $this->response->setContentType('application/json');

        $this->response->setStatusCode(400);
        $this->response->setContent(json_encode([
            'errors' => [
                ['message' => $throwable->getMessage(), 'code' => $throwable->getCode()]
            ]
        ]));
    }

    public function processRequest(ActionRequest $request, ActionResponse $response)
    {
        try {
            parent::processRequest($request, $response);
        } catch (Exception\RuntimeException $e) {
            $this->respondWithErrors($e->getPrevious());
        } catch (\Exception $e) {
            $this->respondWithErrors($e);
        }
    }
}
