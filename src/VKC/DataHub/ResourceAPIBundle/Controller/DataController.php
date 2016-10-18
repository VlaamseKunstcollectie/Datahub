<?php

namespace VKC\DataHub\ResourceAPIBundle\Controller;

use FOS\RestBundle\Controller\Annotations;
use FOS\RestBundle\Request\ParamFetcherInterface;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

use Doctrine\ORM\Tools\Pagination\Paginator;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

use VKC\DataHub\ResourceAPIBundle\Form\Type\DataFormType;

/**
 * REST controller for Data.
 *
 * @author  Kalman Olah <kalman@inuits.eu>
 * @package VKC\DataHub\ResourceAPIBundle
 */
class DataController extends Controller
{
    /**
     * List data.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *       200 = "Returned when successful"
     *     }
     * )
     *
     * @Annotations\Get("/data")
     *
     * @Annotations\QueryParam(name="offset", requirements="\d+", nullable=true, description="Offset from which to start listing entries.")
     * @Annotations\QueryParam(name="limit", requirements="\d{1,2}", default="5", description="How many entries to return.")
     * @Annotations\QueryParam(name="sort", requirements="[a-zA-Z\.]+,(asc|desc|ASC|DESC)", nullable=true, description="Sorting field and direction.")
     *
     * @Annotations\View(
     *     serializerGroups={"list"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     *
     * @return array<mixed>
     */
    public function cgetDatasAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        $offset = intval($paramFetcher->get('offset'));
        $limit = intval($paramFetcher->get('limit'));

        $oauthUtils = $this->get('vkc.datahub.oauth.oauth');
        $dataManager = $this->get('vkc.datahub.resource.data')->setOwnerId($oauthUtils->getClient()->getId());

        $data = $dataManager->cgetData($offset, $limit);

        return $data;
    }

    /**
     * Get a single data.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         200 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @Annotations\Get("/data/{id}", requirements={"id" = "[a-zA-Z0-9-]+"})
     *
     * @Annotations\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param string $id Data ID of entry to return
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function getDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        $oauthUtils = $this->get('vkc.datahub.oauth.oauth');
        $dataManager = $this->get('vkc.datahub.resource.data')->setOwnerId($oauthUtils->getClient()->getId());

        $entity = $dataManager->getData($id);

        if (!$entity) {
            throw $this->createNotFoundException();
        }

        return $entity;
    }

    /**
     * Create a data.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     input = "VKC\DataHub\ResourceAPIBundle\Form\Type\DataFormType",
     *     statusCodes = {
     *         201 = "Returned when successful",
     *         400 = "Returned if the form could not be validated"
     *     }
     * )
     *
     * @Annotations\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true,
     *     statusCode=201
     * )
     *
     * @Annotations\Post("/data")
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     */
    public function postDataAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        $oauthUtils = $this->get('vkc.datahub.oauth.oauth');
        $dataManager = $this->get('vkc.datahub.resource.data')->setOwnerId($oauthUtils->getClient()->getId());
        $dataConverters = $this->get('vkc.datahub.resource.data_converters');

        $form = $this->createForm(DataFormType::class);
        $form->submit($request);

        if ($form->isValid()) {
            $rawData = $form->get('data')->getData();
            $format = $form->get('format')->getData();

            $dataConverter = $dataConverters->getConverter($format);

            try {
                $data = $dataConverter->toArray($rawData);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(400, $e->getMessage());
            }

            $entity = $dataManager->createData([
                'parsed' => $data,
                'raw'    => $rawData,
            ]);
            $entity['_id'] = (string) $entity['_id'];

            return $entity;
        }

        return $form;
    }

    /**
     * Update a data (replaces the entire resource).
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     input = "VKC\DataHub\ResourceAPIBundle\Form\Type\DataFormType",
     *     statusCodes = {
     *         204 = "Returned when successful",
     *         400 = "Returned if the form could not be validated",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @Annotations\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true,
     *     statusCode=204
     * )
     *
     * @Annotations\Put("/data/{id}", requirements={"id" = "[a-zA-Z0-9-]+"})
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param integer $id ID of entry to update
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function putDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        $oauthUtils = $this->get('vkc.datahub.oauth.oauth');
        $dataManager = $this->get('vkc.datahub.resource.data')->setOwnerId($oauthUtils->getClient()->getId());
        $dataConverters = $this->get('vkc.datahub.resource.data_converters');

        $entity = $dataManager->getData($id);

        if (!$entity) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(DataFormType::class, $entity);
        $form->submit($request);

        if ($form->isValid()) {
            $rawData = $form->get('data')->getData();
            $format = $form->get('format')->getData();

            $dataConverter = $dataConverters->getConverter($format);

            try {
                $data = $dataConverter->toArray($rawData);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(400, $e->getMessage());
            }

            $entity = $dataManager->updateData($id, [
                'parsed' => $data,
                'raw'    => $rawData,
            ]);

            return $entity;
        }

        return $form;
    }

    /**
     * Delete a data.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         204 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @Annotations\View(statusCode="204")
     *
     * @Annotations\Delete("/data/{id}", requirements={"id" = "[a-zA-Z0-9-]+"})
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param integer $id ID of entry to delete
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function deleteDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        $oauthUtils = $this->get('vkc.datahub.oauth.oauth');
        $dataManager = $this->get('vkc.datahub.resource.data')->setOwnerId($oauthUtils->getClient()->getId());

        $entity = $dataManager->getData($id);

        if (!$entity) {
            throw $this->createNotFoundException();
        }

        $result = $dataManager->deleteData($id);

        if (!$result) {
            throw new HttpException('The record could not be deleted.');
        }
    }
}
