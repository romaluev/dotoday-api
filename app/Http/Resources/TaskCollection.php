<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use App\Http\Resources\TaskResource;

class TaskCollection extends ResourceCollection
{
    public $collects = TaskResource::class;
}