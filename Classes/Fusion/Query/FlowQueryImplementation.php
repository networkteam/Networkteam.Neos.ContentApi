<?php

namespace Networkteam\Neos\ContentApi\Fusion\Query;

use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

/**
 * Fusion object to evaluate a FlowQuery and return the result as data and meta information.
 * It supports basic pagination using slice.
 */
class FlowQueryImplementation extends AbstractFusionObject
{
    /**
     * The number of rendered nodes, filled only after evaluate() was called.
     *
     * @var integer
     */
    protected $numberOfRenderedNodes;

    protected function getItems(): mixed
    {
        return $this->fusionValue('items');
    }

    protected function getItemName(): ?string
    {
        return $this->fusionValue('itemName');
    }

    protected function getItemKey(): ?string
    {
        return $this->fusionValue('itemKey');
    }

    protected function getIterationName(): ?string
    {
        return $this->fusionValue('iterationName');
    }

    protected function getPage(): ?int
    {
        return $this->fusionValue('page');
    }

    protected function getPerPage(): ?int
    {
        return $this->fusionValue('perPage');
    }

    /**
     * Evaluate the items and return data and meta information
     *
     * @return array
     * @throws FusionException
     */
    public function evaluate()
    {
        $items = $this->getItems();
        if (!($items instanceof FlowQuery)) {
            throw new FusionException('Unexpected value: items must be a FlowQuery instance', 1715165986);
        }

        $page = $this->getPage() ?? 0;
        $perPage = $this->getPerPage();
        $dataItems = $items;
        $collectionTotalCount = count($items);
        $hasMore = false;
        if ($perPage !== null) {
            $start = $page * $perPage;
            $dataItems = $items->slice($start, $start + $perPage);
            $hasMore = $collectionTotalCount > ($start + $perPage);
        }

        $result = [];
        $this->numberOfRenderedNodes = 0;
        $itemName = $this->getItemName();
        if ($itemName === null) {
            throw new FusionException('Missing property: itemName', 1715165987);
        }
        $itemKey = $this->getItemKey();
        $iterationName = $this->getIterationName();

        $itemRenderPath = $this->path . '/itemRenderer';

        foreach ($dataItems as $collectionKey => $collectionElement) {
            $context = $this->runtime->getCurrentContext();
            $context[$itemName] = $collectionElement;

            if ($itemKey !== null) {
                $context[$itemKey] = $collectionKey;
            }

            if ($iterationName !== null) {
                $context[$iterationName] = $this->prepareIterationInformation($collectionTotalCount);
            }

            $this->runtime->pushContextArray($context);

            $result[$collectionKey] = $this->runtime->render($itemRenderPath);

            $this->runtime->popContext();
            $this->numberOfRenderedNodes++;
        }

        // TODO Render additionalMeta properties and merge

        return [
            'data' => $result,
            'meta' => [
                'count' => $collectionTotalCount,
                'page' => $page,
                'perPage' => $perPage,
                'hasMore' => $hasMore
            ]
        ];
    }

    /**
     * @param integer $collectionCount
     * @return array
     */
    protected function prepareIterationInformation($collectionCount)
    {
        $iteration = [
            'index' => $this->numberOfRenderedNodes,
            'cycle' => ($this->numberOfRenderedNodes + 1),
            'isFirst' => false,
            'isLast' => false,
            'isEven' => false,
            'isOdd' => false
        ];

        if ($this->numberOfRenderedNodes === 0) {
            $iteration['isFirst'] = true;
        }
        if (($this->numberOfRenderedNodes + 1) === $collectionCount) {
            $iteration['isLast'] = true;
        }
        if (($this->numberOfRenderedNodes + 1) % 2 === 0) {
            $iteration['isEven'] = true;
        } else {
            $iteration['isOdd'] = true;
        }

        return $iteration;
    }
}
