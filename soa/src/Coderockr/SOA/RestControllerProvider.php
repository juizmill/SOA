<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializerBuilder;

class RestControllerProvider implements ControllerProviderInterface
{
    private $useCache = false;
    private $cache;
    private $em;
    private $entityNamespace;

    public function setCache($cache)
    {
        $this->useCache = true;
        $this->cache = $cache;
    }
    
    public function setEntityManager($em)
    {
        $this->em = $em;
    }

    public function setEntityNamespace($entityNamespace)
    {
        $this->entityNamespace = $entityNamespace;
    }

    private function getEntityName($entity)
    {
        return $this->entityNamespace . '\\' . ucfirst($entity);
    }

    public function find($entity, $id)
    {
        $data = $this->em
                     ->getRepository($this->getEntityName($entity))
                     ->find($id);

        return $data;
    }

    public function findAll($entity)
    {
        $data = $this->em
                     ->getRepository($this->getEntityName($entity))
                     ->findAll();

        return $data;
    }   

    public function create($request, $entity)
    {
        $entityName = $this->getEntityName($entity);
        $entity = new $entityName;

        $data = $request->request->all();
        
        $this->em->persist($this->setData($entity, $data));
        $this->em->flush();

        return $entity;
    }

    private function setData($entity, $data) 
    {
        $class = new \ReflectionClass($entity);
        foreach ($data as $name => $value) {
            if (is_array($value)) { //it's a relationship to another entity
                $id = $value['id'];
                $relatedEntity = $this->getEntityName($name);
                if (isset($value['entityName'])) 
                    $relatedEntity = $this->getEntityName($value['entityName']);
                
                $value = $this->em->find($relatedEntity, $id);
            }
            $method = 'set'. ucfirst($name);
            if ($class->hasMethod($method)) {
               call_user_func(array($entity, $method), $value); 
            }
        }

        return $entity;

    }

    public function update($request, $entity, $id)
    {
        $entityName = $this->getEntityName($entity);
        $entity = $this->find($entity, $id);
        if (!$entity) {
            return false;
        }

        $data = $request->request->all();
        $entity = $this->setData($entity, $data);
        $entity->setUpdated(date('Y-m-d H:i:s'));

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    public function delete($request, $entity, $id)
    {
        $data = $this->em
                     ->getRepository($this->entityNamespace . '\\' . ucfirst($entity))
                     ->find($id);
        if (!$data) {
            return false;
        }
        $this->em->remove($data);
        $this->em->flush();

        return true;
    }

    protected function serialize($data, $type)
    {
        $serializer = SerializerBuilder::create()->build();
        return $serializer->serialize($data, $type);
    }

    public function connect(Application $app)
    {
        $this->setEntityManager($app['orm.em']);
        // creates a new controller based on the default route
        $controllers = $app['controllers_factory'];

        $controllers->get('/', function (Application $app) {
            // return $app->redirect('/hello');
            return 'TODO: documentation';
        });
        
        $controllers->get('/{entity}', function (Application $app, $entity) {
            return $this->serialize($this->findAll($entity), 'json');
        });

        $controllers->get('/{entity}/{id}', function (Application $app, $entity, $id) {
            $data =  $this->find($entity, $id);
            if (!$data) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return $this->serialize($data, 'json');
        })->assert('id', '\d+');

        $controllers->post('/{entity}', function (Application $app, Request $request, $entity) {
            return $this->serialize($this->create($request, $entity), 'json');
        });

        $controllers->put('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            $data = $this->update($request, $entity, $id);

            if (!$data) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return $this->serialize($data, 'json');
        });

        $controllers->delete('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            $deleted = $this->delete($request, $entity, $id);

            if (!$deleted) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return new Response('Data deleted', 200, array('Content-Type' => 'text/json'));
        });

        $controllers->after(function (Request $request, Response $response) {
            $response->headers->set('Content-Type', 'text/json');
        });

        //options - used in cross domain access
        $controllers->match('{entity}/{id}', function ($entity, $id, Request $request) use ($app) 
        {
            return new Response('', 200, array(
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE',
                'Access-Control-Allow-Headers' => 'Authorization'
            ));
        })->method('OPTIONS')->value('id', null);

        $controllers->before(function (Request $request) use ($app) {
            if ($request->getMethod() == 'OPTIONS') {
                return;
            }

            //@TODO: review this
            // if( ! $request->headers->has('authorization')){
            //     return new Response('Unauthorized', 401);
            // }

            // require_once getenv('APPLICATION_PATH').'/configs/clients.php';
            // if (!in_array($request->headers->get('authorization'), array_keys($clients))) {
            //     return new Response('Unauthorized', 401);
            // }
        });

        return $controllers;
    }   
}