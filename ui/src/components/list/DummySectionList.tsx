import List from '@/components/list/List';
import { useGetDummySectionsQuery } from '@/services/sections';
import { Text } from '@radix-ui/themes';

const DummySectionList = () => {
  const { data: fakeSections } = useGetDummySectionsQuery();

  return (
    <>
      <Text size="1">
        The section template listed below is hard coded and is a proof of
        concept. It should allow the user to add a hero with an image below it
        in a single action.
      </Text>
      <List
        items={fakeSections}
        isLoading={false}
        type="section"
        label="Dummy Section templates"
      />
    </>
  );
};

export default DummySectionList;
