<?php

namespace Claroline\CoreBundle\Manager;

use Mockery as m;
use Claroline\CoreBundle\Library\Testing\MockeryTestCase;

class RightsManagerTest extends MockeryTestCase
{
    private $rightsRepo;
    private $resourceRepo;
    private $roleManager;
    private $roleRepo;
    private $resourceTypeRepo;
    private $translator;
    private $om;
    private $dispatcher;

    public function setUp()
    {
        parent::setUp();

        $this->rightsRepo = $this->mock('Claroline\CoreBundle\Repository\ResourceRightsRepository');
        $this->roleManager = $this->mock('Claroline\CoreBundle\Manager\RoleManager');
        $this->resourceRepo = $this->mock('Claroline\CoreBundle\Repository\AbstractResourceRepository');
        $this->roleRepo = $this->mock('Claroline\CoreBundle\Repository\RoleRepository');
        $this->resourceTypeRepo = $this->mock('Claroline\CoreBundle\Repository\ResourceTypeRepository');
        $this->translator = $this->mock('Symfony\Component\Translation\Translator');
        $this->dispatcher = $this->mock('Claroline\CoreBundle\Event\StrictDispatcher');
        $this->om = $this->mock('Claroline\CoreBundle\Persistence\ObjectManager');
    }

    public function testUpdateRightsTree()
    {
        $manager = $this->getManager();

        $role = $this->mock('Claroline\CoreBundle\Entity\Role');
        $resource = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $descendantA = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $descendantB = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $rightsParent = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $rightsDescendantA = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $rightsDescendantB = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $rightsParent->shouldReceive('getResource')->andReturn($resource);
        $rightsDescendantB->shouldReceive('getResource')->andReturn($descendantB);
        $rightsParent->shouldReceive('getRole')->andReturn($role);
        $rightsDescendantB->shouldReceive('getRole')->andReturn($role);

        $this->rightsRepo
            ->shouldReceive('findRecursiveByResourceAndRole')
            ->once()
            ->with($resource, $role)
            ->andReturn(array($rightsParent, $rightsDescendantB));

        $this->resourceRepo
            ->shouldReceive('findDescendants')
            ->once()
            ->with($resource, true)
            ->andReturn(array($resource, $descendantA, $descendantB));

        $this->om->shouldReceive('factory')->once()->andReturn($rightsDescendantA);
        $rightsDescendantA->shouldReceive('setRole')->once()->with($role);
        $rightsDescendantA->shouldReceive('setResource')->once()->with($descendantA);
        $this->om->shouldReceive('persist')->once()->with($rightsDescendantA);
        $this->om->shouldReceive('flush')->once();

        $results = $manager->updateRightsTree($role, $resource);
        $this->assertEquals(3, count($results));
    }

    public function testNonRecursiveCreate()
    {
        $manager = $this->getManager(array('getEntity', 'setPermissions'));

        $perms = array(
            'canCopy' => true,
            'canOpen' => false,
            'canDelete' => true,
            'canEdit' => false,
            'canExport' => true
        );

        $types = array(
            new \Claroline\CoreBundle\Entity\Resource\ResourceType(),
            new \Claroline\CoreBundle\Entity\Resource\ResourceType(),
            new \Claroline\CoreBundle\Entity\Resource\ResourceType()
        );
        $newRights = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $this->om->shouldReceive('factory')->with('Claroline\CoreBundle\Entity\Resource\ResourceRights')
            ->andReturn($newRights);
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $role = new \Claroline\CoreBundle\Entity\Role();
        $newRights->shouldReceive('setRole')->once()->with($role);
        $newRights->shouldReceive('setResource')->once()->with($resource);
        $newRights->shouldReceive('setCreatableResourceTypes')->once()->with($types);
        $manager->shouldReceive('setPermissions')->once()->with($newRights, $perms);
        $this->om->shouldReceive('persist')->once()->with($newRights);
        $this->om->shouldReceive('flush')->once();
        $manager->create($perms, $role, $resource, false, $types);
    }

    public function testEditPerms()
    {
        $manager = $this->getManager(array('getOneByRoleAndResource', 'setPermissions', 'logChangeSet'));

        $perms = array(
            'canCopy' => true,
            'canOpen' => false,
            'canDelete' => true,
            'canEdit' => false,
            'canExport' => true
        );

        $resource = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $rights = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $role = $this->mock('Claroline\CoreBundle\Entity\Role');
        $manager->shouldReceive('getOneByRoleAndResource')->once()->with($role, $resource)->andReturn($rights);
        $manager->shouldReceive('setPermissions')->once()->with($rights, $perms);
        $this->om->shouldReceive('persist')->once()->with($rights);
        $manager->shouldReceive('logChangeSet')->once()->with($rights);

        $manager->editPerms($perms, $role, $resource, false);
    }

    public function testEditCreationRights()
    {
        $manager = $this->getManager(array('getOneByRoleAndResource', 'setPermissions', 'logChangeSet'));

        $types = array(
            new \Claroline\CoreBundle\Entity\Resource\ResourceType(),
            new \Claroline\CoreBundle\Entity\Resource\ResourceType(),
            new \Claroline\CoreBundle\Entity\Resource\ResourceType(),
            new \Claroline\CoreBundle\Entity\Resource\ResourceType()
        );

        $resource = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $rights = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $role = $this->mock('Claroline\CoreBundle\Entity\Role');
        $this->om->shouldReceive('startFlushSuite')->once();
        $manager->shouldReceive('getOneByRoleAndResource')->once()->with($role, $resource)->andReturn($rights);
        $rights->shouldReceive('setCreatableResourceTypes')->once()->with($types);
        $this->om->shouldReceive('persist')->once()->with($rights);
        $this->om->shouldReceive('endFlushSuite')->once();
        $manager->shouldReceive('logChangeSet')->once()->with($rights);

        $manager->editCreationRights($types, $role, $resource, false);
    }

    public function testCopy()
    {
        $manager = $this->getManager(array('create'));
        $originalRights = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $original = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $resource = $this->mock('Claroline\CoreBundle\Entity\Resource\AbstractResource');
        $role = new \Claroline\CoreBundle\Entity\Role();

        $perms = array(
            'canCopy' => true,
            'canOpen' => false,
            'canDelete' => true,
            'canEdit' => false,
            'canExport' => true
        );

        $this->rightsRepo
            ->shouldReceive('findBy')
            ->once()
            ->with(array('resource' => $original))
            ->andReturn(array($originalRights));

        m::getConfiguration()->allowMockingNonExistentMethods(true);
        $originalRights->shouldReceive('getCreatableResourceTypes->toArray')->once()->andReturn(array());
        $originalRights->shouldReceive('getRole')->once()->andReturn($role);
        $originalRights->shouldReceive('getPermissions')->once()->andReturn($perms);
        $manager->shouldReceive('create')->once()->with($perms, $role, $resource, false, array());
        $this->om->shouldReceive('startFlushSuite')->once();
        $this->om->shouldReceive('endFlushSuite')->once();

        $manager->copy($original, $resource);
    }

    public function testSetPermissions()
    {
        $perms = array(
            'canCopy' => true,
            'canOpen' => false,
            'canDelete' => true,
            'canEdit' => false,
            'canExport' => true
        );

        $rights = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $rights->shouldReceive('setCanCopy')->once()->with(true);
        $rights->shouldReceive('setCanOpen')->once()->with(false);
        $rights->shouldReceive('setCanDelete')->once()->with(true);
        $rights->shouldReceive('setCanEdit')->once()->with(false);
        $rights->shouldReceive('setCanExport')->once()->with(true);

        $this->assertEquals($rights, $this->getManager()->setPermissions($rights, $perms));
    }

    public function testAddRolesToPermsArray()
    {
        $role = $this->mock('Claroline\CoreBundle\Entity\Role');
        $baseRoles = array($role);
        $perms = array(
            'ROLE_WS_MANAGER' => array('perms' => 'perms')
        );

        $this->roleManager->shouldReceive('getRoleBaseName')
            ->with('ROLE_WS_MANAGER_GUID')
            ->andReturn('ROLE_WS_MANAGER');
        $role->shouldReceive('getName')->andReturn('ROLE_WS_MANAGER_GUID');

        $result = array('ROLE_WS_MANAGER' => array('perms' => 'perms', 'role' => $role));
        $this->assertEquals($result, $this->getManager()->addRolesToPermsArray($baseRoles, $perms));
    }

    public function testGetOneExistingByRoleAndResource()
    {
        $role = new \Claroline\CoreBundle\Entity\Role();
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $rr = new \Claroline\CoreBundle\Entity\Resource\ResourceRights();

        $this->rightsRepo->shouldReceive('findOneBy')->with(array('resource' => $resource, 'role' => $role))
            ->once()->andReturn($rr);

        $this->assertEquals($rr, $this->getManager()->getOneByRoleAndResource($role, $resource));
    }

    public function testGetOneFictiveByRoleAndResource()
    {
        $role = new \Claroline\CoreBundle\Entity\Role();
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $rr = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');

        $this->rightsRepo->shouldReceive('findOneBy')->with(array('resource' => $resource, 'role' => $role))
            ->once()->andReturn(null);

        $this->om->shouldReceive('factory')->once()
            ->with('Claroline\CoreBundle\Entity\Resource\ResourceRights')
            ->andReturn($rr);

        $rr->shouldReceive('setRole')->once()->with($role);
        $rr->shouldReceive('setResource')->once()->with($resource);

        $this->assertEquals($rr, $this->getManager()->getOneByRoleAndResource($role, $resource));
    }

    public function testGetCreatableTypes()
    {
        $role = $this->mock('Claroline\CoreBundle\Entity\Role');
        $roles = array($role);
        $directory = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $types = array(array('name' => 'directory'));
        $this->rightsRepo->shouldReceive('findCreationRights')->once()
            ->with($roles, $directory)->andReturn($types);

        $this->translator->shouldReceive('trans')->once()->with('directory', array(), 'resource')
            ->andReturn('dossier');

        $res = array('directory' => 'dossier');

        $this->assertEquals($res, $this->getManager()->getCreatableTypes($roles, $directory));
    }

    public function testRecursiveCreation()
    {
        $manager = $this->getManager(array('updateRightsTree', 'setPermissions'));
        $role = new \Claroline\CoreBundle\Entity\Role();
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $rr = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $resourceRights = array($rr);

        $this->om->shouldReceive('startFlushSuite')->once();
        $manager->shouldReceive('updateRightsTree')->once()->with($role, $resource)->andReturn($resourceRights);
        $manager->shouldReceive('setPermissions')->once()->with($rr, array());
        $rr->shouldReceive('setCreatableResourceTypes')->once()->with(array());
        $this->om->shouldReceive('persist')->once()->with($rr);
        $this->om->shouldReceive('endFlushSuite')->once();

        $manager->recursiveCreation(array(), $role, $resource, array());
    }

    public function testLogChangeSet()
    {
        $uow = $this->mock('Doctrine\ORM\UnitOfWork');
        $rr = $this->mock('Claroline\CoreBundle\Entity\Resource\ResourceRights');
        $role = new \Claroline\CoreBundle\Entity\Role();
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $rr->shouldReceive('getRole')->once()->andReturn($role);
        $rr->shouldReceive('getResource')->once()->andReturn($resource);
        $this->om->shouldReceive('getUnitOfWork')->andReturn($uow);
        $uow->shouldReceive('computeChangeSets')->once();
        $uow->shouldReceive('getEntityChangeSet')->once()->with($rr)->andReturn(array());
        $this->dispatcher->shouldReceive('dispatch')->once()
            ->with('log', 'Log\LogWorkspaceRoleChangeRight', array($role, $resource, array()));
        $this->getManager()->logChangeSet($rr);
    }

    public function testGetNonAdminRights()
    {
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $this->rightsRepo->shouldReceive('findNonAdminRights')->once()->with($resource)->andReturn(array());
        $this->assertEquals(array(), $this->getManager()->getNonAdminRights($resource));
    }

    public function testGetResourceTypes()
    {
        $this->resourceTypeRepo->shouldReceive('findAll')->once()->andReturn(array());
        $this->assertEquals(array(), $this->getManager()->getResourceTypes());
    }

    public function testGetMaximumRights()
    {
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $role = new \Claroline\CoreBundle\Entity\Role();
        $roles = array($role);

        $this->rightsRepo->shouldReceive('findMaximumRights')->once()->with($roles, $resource)->andReturn(array());
        $this->assertEquals(array(), $this->getManager()->getMaximumRights($roles, $resource));
    }

    public function testGetCreationRights()
    {
        $resource = new \Claroline\CoreBundle\Entity\Resource\Directory();
        $role = new \Claroline\CoreBundle\Entity\Role();
        $roles = array($role);

        $this->rightsRepo->shouldReceive('findCreationRights')->once()->with($roles, $resource)->andReturn(array());
        $this->assertEquals(array(), $this->getManager()->getCreationRights($roles, $resource));
    }

    private function getManager(array $mockedMethods = array())
    {
        $this->om->shouldReceive('getRepository')->with('ClarolineCoreBundle:Resource\ResourceRights')
            ->andReturn($this->rightsRepo);
        $this->om->shouldReceive('getRepository')->with('ClarolineCoreBundle:Resource\AbstractResource')
            ->andReturn($this->resourceRepo);
        $this->om->shouldReceive('getRepository')->with('ClarolineCoreBundle:Role')
            ->andReturn($this->roleRepo);
        $this->om->shouldReceive('getRepository')->with('ClarolineCoreBundle:Resource\ResourceType')
            ->andReturn($this->resourceTypeRepo);

        if (count($mockedMethods) === 0) {
            return new RightsManager(
                $this->translator,
                $this->om,
                $this->dispatcher,
                $this->roleManager
            );
        } else {
            $stringMocked = '[';
                $stringMocked .= array_pop($mockedMethods);

            foreach ($mockedMethods as $mockedMethod) {
                $stringMocked .= ",{$mockedMethod}";
            }

            $stringMocked .= ']';

            return $this->mock(
                'Claroline\CoreBundle\Manager\RightsManager' . $stringMocked,
                array(
                    $this->translator,
                    $this->om,
                    $this->dispatcher,
                    $this->roleManager
                )
            );
        }
    }
}
